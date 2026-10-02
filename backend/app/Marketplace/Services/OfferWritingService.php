<?php

namespace App\Marketplace\Services;

use App\Platform\Models\Currency;
use App\Catalog\Models\ProductVariant;
use App\Core\Exceptions\ApiRefused;
use App\Marketplace\Models\Offer;
use App\Marketplace\Models\OfferItem;
use App\Platform\Services\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * What it takes for a seller to put a listing on the platform, and to change
 * one they still controls.
 *
 * The rules live here rather than in the controller because they are what the
 * market *is*: currency must be one the platform runs on, goods must belong to
 * the business declaring them, a category is the product's not the seller's
 * opinion, and a price buyers may act on cannot be rewritten under their feet.
 * A second door into a listing — an import, a console button — must meet the
 * same refusals, and a rule kept only in an HTTP handler guards only one door.
 */
class OfferWritingService
{
    public function __construct(
        protected SystemSettingsService $settingsService,
    ) {}

    /**
     * Create a listing for one organization.
     *
     * @param array<string, mixed> $validated the request's validated payload
     *
     * @throws ApiRefused with the exact envelope the API answers with
     */
    public function create(int $sellerOrgId, array $validated): Offer
    {
        $variant = ProductVariant::with('product')->findOrFail($validated['product_variant_id']);

        $currencyCode = strtoupper($validated['currency_code'] ?? (string) $this->settingsService->get('platform.default_currency'));

        if (!Currency::isActive($currencyCode)) {
            throw new ApiRefused(
                'العملة المحددة غير موجودة أو غير مفعلة',
                'CURRENCY_NOT_ACTIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // A seller who names a category has stated which goods they believe they
        // are listing. The listing's category is the product's — nothing is
        // stored from this field any more — so what a disagreement can only mean
        // is that the client and the platform are looking at different products.
        // Refusing is the honest answer; quietly writing the seller's version was
        // never possible, and writing the platform's while pretending otherwise
        // would hide the mistake.
        if ($validated['category_id'] ?? null) {
            if ((int) $validated['category_id'] !== (int) $variant->product->category_id) {
                throw new ApiRefused(
                    'تصنيف العرض لا يطابق تصنيف المنتج',
                    'CATEGORY_PRODUCT_MISMATCH',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // Verify warehouse belongs to seller's organization
        $warehouseBelongsToSeller = DB::table('warehouses')
            ->where('id', $validated['warehouse_id'])
            ->where('organization_id', $sellerOrgId)
            ->exists();

        if (!$warehouseBelongsToSeller) {
            throw new ApiRefused(
                'المستودع المحدد لا ينتمي لمنشأتك',
                'WAREHOUSE_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }

        // The same question the warehouse asks, asked of the goods: an offer
        // declares what this business is selling, so it cannot be allowed to
        // trade under another business' catalog entry. An unowned product is a
        // platform reference entry and is open to everyone.
        $variantOwnerId = $variant->product?->organization_id;

        if ($variantOwnerId !== null && (int) $variantOwnerId !== (int) $sellerOrgId) {
            throw new ApiRefused(
                'الصنف المحدد لا ينتمي لمنشأتك',
                'VARIANT_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }

        $unitPrice = (float) $validated['unit_price'];

        // Determine initial status based on platform config
        $requiresReview = (bool) $this->settingsService->get('offer.requires_review', false);
        $asDraft        = (bool) ($validated['as_draft'] ?? false);

        // A draft is the seller's own workspace: it is not awaiting review and it
        // is not published, because it has not been offered yet.
        $initialStatus = $asDraft
            ? Offer::STATUS_DRAFT
            : ($requiresReview ? Offer::STATUS_PENDING_REVIEW : Offer::STATUS_ACTIVE);
        $publishedAt = ($asDraft || $requiresReview) ? null : Carbon::now();

        return DB::transaction(function () use (
            $validated, $sellerOrgId, $variant,
            $unitPrice, $initialStatus, $publishedAt, $currencyCode
        ) {
            // `forceFill()` and not `create()`: the four fields this sets that a
            // request may not — `status` and the three quantity counters — are
            // removed from `Offer::$fillable`, and this is the platform opening
            // a listing on a seller's behalf and computing the stock arithmetic
            // from the declared `total_quantity`. Writing them here is the
            // server stating a consequence, not accepting one.
            $newOffer = new Offer();
            $newOffer->forceFill([
                'seller_organization_id'  => $sellerOrgId,
                'warehouse_id'            => $validated['warehouse_id'],
                'product_variant_id'      => $variant->id,
                'title_ar'                => $validated['title_ar'],
                'title_en'                => $validated['title_en'] ?? null,
                'description_ar'          => $validated['description_ar'] ?? null,
                'description_en'          => $validated['description_en'] ?? null,
                'opportunity_reason'      => $validated['opportunity_reason'],
                'opportunity_reason_notes'=> $validated['opportunity_reason_notes'] ?? null,
                'status'                  => $initialStatus,
                'min_order_quantity'      => $validated['min_order_quantity'],
                'max_order_quantity'      => $validated['max_order_quantity'] ?? null,
                'total_quantity'          => $validated['total_quantity'],
                'available_quantity'      => $validated['total_quantity'],
                'reserved_quantity'       => 0.00,
                'sold_quantity'           => 0.00,
                'unit'                    => $validated['unit'],
                'unit_price'              => $unitPrice,
                'currency_code'           => $currencyCode,
                'production_date'         => $validated['production_date'] ?? null,
                'expiry_date'             => $validated['expiry_date'] ?? null,
                'storage_condition'       => $validated['storage_condition'] ?? 'ambient',
                'delivery_terms'          => $validated['delivery_terms'] ?? 'BOTH',
                'inspection_window_hours' => $validated['inspection_window_hours'] ?? $this->settingsService->get('inspection.window', 48),
                'published_at'            => $publishedAt,
                'expires_at'              => $validated['expires_at'] ?? null,
                'show_seller_identity'    => (bool) ($validated['show_seller_identity'] ?? true),
            ])->save();

            OfferItem::create([
                'offer_id'          => $newOffer->id,
                'product_id'        => $variant->product_id,
                'product_variant_id'=> $variant->id,
                'quantity'          => $validated['total_quantity'],
                'unit_price'        => $unitPrice,
                'currency_code'     => $newOffer->currency_code,
            ]);

            return $newOffer;
        });
    }

    /**
     * Is this listing still in a state its seller may rewrite?
     *
     * Asked by the controller before validation, exactly as the monolithic
     * handler asked it before extracting: a buyer-visible listing refusing an
     * edit is a different answer from a field being malformed, and the client
     * has to be told the first thing, not the second.
     *
     * @throws ApiRefused
     */
    public function assertEditable(Offer $offer): void
    {
        if (!in_array($offer->status, [Offer::STATUS_DRAFT, Offer::STATUS_PAUSED], true)) {
            throw new ApiRefused(
                'لا يمكن تعديل العرض إلا وهو مسودة أو متوقف; أوقف النشر أولاً',
                'OFFER_NOT_EDITABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    /**
     * Edit a listing the seller still controls.
     *
     * Deliberately narrow:
     *   * Only DRAFT and PAUSED offers are editable. Rewriting the price of an
     *     offer buyers are looking at is how a marketplace earns disputes, so
     *     the seller pauses first — which also puts the withdrawal in the audit
     *     trail instead of leaving it invisible.
     *   * The product variant and warehouse are not editable. They are what the
     *     offer *is*: matching, location search and the deal snapshot all key off
     *     them. A new product or a different warehouse is a new offer.
     *   * `total_quantity` may only change while nothing has been reserved or
     *     sold, so the row cannot end up claiming more stock than it has
     *     already committed — the invariant the CHECK constraint enforces.
     *
     * @param array<string, mixed> $validated
     *
     * @throws ApiRefused
     */
    public function update(Offer $offer, array $validated): Offer
    {
        $this->assertEditable($offer);

        if (isset($validated['total_quantity'])) {
            $committed = (float) $offer->reserved_quantity + (float) $offer->sold_quantity;

            if ($committed > 0.0001) {
                throw new ApiRefused(
                    'الكمية المعلن عنها لا يمكن تعديلها بعد حجز أو بيع جزء من العرض',
                    'OFFER_QUANTITY_COMMITTED',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // The quantity rules are about the offer as a whole, and a client may
        // legitimately send only one of these fields, so they are compared
        // against the stored values for whatever was not sent. A cross-field
        // validator rule would have demanded all three on every edit.
        $minOrder  = (float) ($validated['min_order_quantity'] ?? $offer->min_order_quantity);
        $maxOrder  = array_key_exists('max_order_quantity', $validated)
            ? ($validated['max_order_quantity'] === null ? null : (float) $validated['max_order_quantity'])
            : ($offer->max_order_quantity === null ? null : (float) $offer->max_order_quantity);
        $total     = (float) ($validated['total_quantity'] ?? $offer->total_quantity);
        $available = (float) $offer->available_quantity;

        $fieldErrors = [];

        if ($total < $minOrder) {
            $fieldErrors['total_quantity'][] = 'الكمية الإجمالية أقل من الحد الأدنى للطلب';
        }

        if ($maxOrder !== null && $maxOrder < $minOrder) {
            $fieldErrors['max_order_quantity'][] = 'الحد الأقصى للطلب أقل من الحد الأدنى';
        }

        if ($minOrder > $available) {
            $fieldErrors['min_order_quantity'][] = 'الحد الأدنى للطلب أكبر من الكمية المتاحة';
        }

        if ($fieldErrors !== []) {
            throw new ApiRefused(
                'Validation failed',
                'VALIDATION_FAILED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $fieldErrors
            );
        }

        DB::transaction(function () use ($offer, $validated) {
            // `available_quantity` is derived, never accepted. A seller who
            // raises `total_quantity` on an offer with nothing committed gets a
            // matching available figure — but the number comes from the server's
            // reading of the offer, and `available_quantity` is not in
            // `$fillable`, so the same array carrying it cannot also smuggle a
            // different one in from the request body.
            $declaresTotal = array_key_exists('total_quantity', $validated);

            $offer->fill($validated);

            // Assigned on the model and written in the same statement, not in a
            // second `save()`: the database checks
            // `reserved + sold + available <= total` on every UPDATE, so a row
            // whose declared total was lowered while its available still held
            // the old figure passes through a state MySQL refuses — and the
            // repair statement never gets to run. One write, one legal row.
            if ($declaresTotal) {
                $offer->available_quantity = (float) $validated['total_quantity'];
            }

            $offer->save();

            // The line items describe what is inside this offer, so they follow
            // the offer's own price and quantity rather than drifting from them.
            OfferItem::updateOrCreate(
                ['offer_id' => $offer->id, 'product_variant_id' => $offer->product_variant_id],
                [
                    'product_id'    => $offer->variant->product_id,
                    'quantity'      => $offer->total_quantity,
                    'unit_price'    => $offer->unit_price,
                    'currency_code' => $offer->currency_code,
                ]
            );
        });

        return $offer;
    }
}
