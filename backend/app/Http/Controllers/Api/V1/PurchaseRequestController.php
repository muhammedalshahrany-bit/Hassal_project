<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DemandMatch;
use App\Models\PurchaseRequest;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = TenantContext::id();

        if (!$organizationId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $requests = PurchaseRequest::with(['demand', 'offer.variant.product', 'buyerOrganization', 'sellerOrganization'])
            ->where(function ($query) use ($organizationId) {
                $query->where('buyer_organization_id', $organizationId)
                    ->orWhere('seller_organization_id', $organizationId);
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiResponse::paginated($requests, 'Purchase requests retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $requestRecord = PurchaseRequest::with(['demand', 'offer', 'buyerOrganization', 'sellerOrganization'])
            ->findOrFail($id);

        return ApiResponse::success($requestRecord, 'Purchase request retrieved successfully');
    }

    public function storeFromMatch(Request $request, int $matchId): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error('Active buyer organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $match = DemandMatch::with(['demand', 'offer'])->findOrFail($matchId);

        if ($match->demand->buyer_organization_id !== $buyerOrgId) {
            return ApiResponse::error('This match belongs to another organization', 'MATCH_ACCESS_DENIED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'requested_quantity' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivery_preference' => ['nullable', 'in:PICKUP,DELIVERY'],
        ]);

        $requestedQuantity = (float) ($validated['requested_quantity'] ?? $match->requested_quantity);
        $maxQuantity = (float) $match->offer->available_quantity;
        if ($requestedQuantity > $maxQuantity) {
            return ApiResponse::error('Requested quantity exceeds available quantity', 'QUANTITY_EXCEEEDS_AVAILABLE', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $purchaseRequest = PurchaseRequest::create([
            'purchase_request_number' => 'PR-' . now()->format('ymd') . '-' . strtoupper(str()->random(4)),
            'demand_id' => $match->demand_id,
            'demand_match_id' => $match->id,
            'offer_id' => $match->offer_id,
            'buyer_organization_id' => $buyerOrgId,
            'seller_organization_id' => $match->seller_organization_id,
            'buyer_user_id' => $request->user()->id,
            'seller_user_id' => $match->offer->seller_user_id ?? $request->user()->id,
            'requested_quantity' => $requestedQuantity,
            'unit' => $match->offer->unit,
            'requested_price' => $match->offer->unit_price,
            'currency_code' => $match->offer->currency_code,
            'delivery_preference' => $validated['delivery_preference'] ?? 'PICKUP',
            'status' => PurchaseRequest::STATUS_REQUESTED,
            'notes' => $validated['notes'] ?? null,
        ]);

        $match->update(['status' => DemandMatch::STATUS_USED]);

        return ApiResponse::success(
            $purchaseRequest->load(['demand', 'offer', 'buyerOrganization', 'sellerOrganization']),
            'Purchase request created successfully',
            Response::HTTP_CREATED
        );
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::with(['offer', 'demand'])->findOrFail($id);
        $sellerOrgId = TenantContext::id();

        if ($purchaseRequest->seller_organization_id !== $sellerOrgId) {
            return ApiResponse::error('Only the seller organization may accept this request', 'SELLER_ACCEPT_DENIED', Response::HTTP_FORBIDDEN);
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_ACCEPTED,
            'notes' => $request->input('notes', $purchaseRequest->notes),
        ]);

        return ApiResponse::success($purchaseRequest, 'Purchase request accepted');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::with(['offer', 'demand'])->findOrFail($id);
        $sellerOrgId = TenantContext::id();

        if ($purchaseRequest->seller_organization_id !== $sellerOrgId) {
            return ApiResponse::error('Only the seller organization may reject this request', 'SELLER_REJECT_DENIED', Response::HTTP_FORBIDDEN);
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_REJECTED,
            'notes' => $request->input('reason', $purchaseRequest->notes),
        ]);

        return ApiResponse::success($purchaseRequest, 'Purchase request rejected');
    }

    public function propose(Request $request, int $id): JsonResponse
    {
        $purchaseRequest = PurchaseRequest::with(['offer', 'demand'])->findOrFail($id);
        $sellerOrgId = TenantContext::id();

        if ($purchaseRequest->seller_organization_id !== $sellerOrgId) {
            return ApiResponse::error('Only the seller organization may propose changes', 'SELLER_PROPOSE_DENIED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'proposed_terms' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_PROPOSED,
            'proposed_terms' => $validated['proposed_terms'],
            'notes' => $validated['notes'] ?? $purchaseRequest->notes,
        ]);

        return ApiResponse::success($purchaseRequest, 'Seller proposal submitted');
    }
}
