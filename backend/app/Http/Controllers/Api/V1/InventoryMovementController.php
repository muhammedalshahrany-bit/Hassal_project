<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InventoryMovement;
use App\Models\Offer;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InventoryMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();

        // The selects must name real columns. `warehouses` has name/city and
        // `product_variants` has sku/name_ar, so asking for name_ar/city_ar/
        // title_ar here made the whole listing a SQL error rather than a page.
        $query = InventoryMovement::with([
            'warehouse:id,name,code,city,country_code',
            'productVariant:id,sku,name_ar,name_en',
            'user:id,name',
        ]);

        if (!$request->user()->hasRole('SuperAdmin') && $orgId) {
            $query->where('organization_id', $orgId);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('offer_id')) {
            $query->where('offer_id', $request->integer('offer_id'));
        }

        $movements = $query->orderBy('id', 'desc')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ApiResponse::paginated($movements, 'Inventory movements retrieved');
    }

    /**
     * Record a stock movement against one of the seller's own offers.
     *
     * The version this replaces took a warehouse and a variant and wrote a row
     * with `previous_quantity = 0, new_quantity = 0`. Nothing in HASSAL tracks
     * stock per warehouse and variant — the only quantity the platform holds is
     * an offer's `available_quantity` — so that row asserted nothing true, and
     * the numbers it appeared to describe did not move. An adjustment therefore
     * names the offer it adjusts and changes that offer's quantities in the
     * same transaction, under the invariant the database already enforces:
     * `reserved + sold + available <= total`.
     */
    public function recordAdjustment(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error(
                'مطلوب منشأة نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'type'     => ['required', Rule::in([
                InventoryMovement::TYPE_IN,
                InventoryMovement::TYPE_ADJUSTED,
                InventoryMovement::TYPE_RETURNED,
            ])],
            'quantity' => ['required', 'numeric'],
            'reason'   => ['required', 'string', 'max:500'],
        ]);

        // Restock and return describe goods arriving, so they cannot be
        // negative. Only a count correction may take quantity away.
        $quantity = (float) $validated['quantity'];
        if ($quantity === 0.0) {
            return ApiResponse::error(
                'الكمية لا يمكن أن تكون صفراً',
                'QUANTITY_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (in_array($validated['type'], [InventoryMovement::TYPE_IN, InventoryMovement::TYPE_RETURNED], true)
            && $quantity < 0) {
            return ApiResponse::error(
                'الكمية في هذا النوع من الحركات يجب أن تكون أكبر من صفر',
                'QUANTITY_MUST_BE_POSITIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The offer row is the lock. Every writer of these counters takes it,
        // so a reservation and an adjustment cannot interleave.
        $result = DB::transaction(function () use ($validated, $quantity, $orgId, $request) {
            $offer = Offer::whereKey($validated['offer_id'])
                ->where('seller_organization_id', $orgId)
                ->lockForUpdate()
                ->first();

            if (!$offer) {
                return ['missing' => true];
            }

            if (in_array($offer->status, [Offer::STATUS_CANCELLED, Offer::STATUS_EXPIRED], true)) {
                return ['closed' => true];
            }

            $previousQuantity = (float) $offer->available_quantity;
            $newAvailable = $previousQuantity + $quantity;
            $newTotal = (float) $offer->total_quantity + $quantity;
            $committed = (float) $offer->reserved_quantity + (float) $offer->sold_quantity;

            if ($newAvailable < 0 || $newTotal < $committed) {
                return [
                    'infeasible' => true,
                    'maximum_reduction' => $previousQuantity,
                ];
            }

            $offer->update([
                'available_quantity' => $newAvailable,
                'total_quantity'     => $newTotal,
                'status'             => Offer::statusAfterQuantityChange((string) $offer->status, $newAvailable),
            ]);

            $movement = InventoryMovement::create([
                'organization_id'    => $orgId,
                'warehouse_id'       => $offer->warehouse_id,
                'product_variant_id' => $offer->product_variant_id,
                'offer_id'           => $offer->id,
                'user_id'            => $request->user()->id,
                'type'               => $validated['type'],
                'quantity'           => $quantity,
                'previous_quantity'  => $previousQuantity,
                'new_quantity'       => $newAvailable,
                'reference_type'     => Offer::class,
                'reference_id'       => (string) $offer->id,
                'reason'             => $validated['reason'],
            ]);

            return ['movement' => $movement];
        });

        if ($result['missing'] ?? false) {
            return ApiResponse::error(
                'العرض المحدد غير موجود أو لا ينتمي لمنشأتك',
                'OFFER_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($result['closed'] ?? false) {
            return ApiResponse::error(
                'لا يمكن تسجيل حركة مخزون على عرض منتهٍ أو ملغى',
                'OFFER_CLOSED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($result['infeasible'] ?? false) {
            return ApiResponse::error(
                'التعديل المطلوب يجعل الكميات غير متسقة مع المحجوز أو المبيع',
                'QUANTITY_INFEASIBLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['maximum_reduction' => $result['maximum_reduction']]
            );
        }

        return ApiResponse::success(
            $result['movement']->load([
                'warehouse:id,name,code,city,country_code',
                'productVariant:id,sku,name_ar,name_en',
            ]),
            'تم تسجيل حركة المخزون وتحديث كميات العرض',
            Response::HTTP_CREATED
        );
    }
}
