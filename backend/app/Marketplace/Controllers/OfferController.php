<?php

namespace App\Marketplace\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Marketplace\Resources\OfferResource;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Marketplace\Models\Offer;
use App\Marketplace\Support\OfferInputRules;
use App\Marketplace\Services\GeoDiscoveryService;
use App\Marketplace\Services\OfferLifecycleService;
use App\Marketplace\Services\OfferFeaturingService;
use App\Marketplace\Services\OfferModerationContext;
use App\Marketplace\Services\OfferQueries;
use App\Marketplace\Services\OfferWritingService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The listings HTTP surface: browse, shelf, queue, write, lifecycle.
 *
 * This class answers HTTP and nothing else. Every question of substance —
 * whether a currency is one the platform runs on, whether a draft may be
 * pinned, whether a seller may approve their own queue — is decided in
 * `App\Marketplace\Services`, so a second door into a listing meets the same
 * rules as this one, and the tests that pin those rules do not depend on an
 * HTTP round-trip to reach them.
 *
 * Where the other pieces of a listing request live: the field vocabulary a
 * creation or an edit is validated under is `Support\OfferInputRules`; which
 * rows may be read, and with what loaded, is `Services\OfferQueries`; the
 * moderation decisions a reviewer may give are `Offer::REVIEW_DECISIONS`.
 *
 * The refusal pattern: services throw `ApiRefused` carrying the exact message,
 * code, status and details the API has always answered with; `refused()`
 * translates mechanically. No response shape lives in a service, and no
 * business rule lives here.
 */
class OfferController extends Controller
{
    public function __construct(
        protected GeoDiscoveryService    $geoService,
        protected OfferQueries           $queries,
        protected OfferWritingService    $writing,
        protected OfferLifecycleService  $lifecycle,
        protected OfferModerationContext $moderationContext,
        protected OfferFeaturingService  $featuring,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC / MARKETPLACE ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Public marketplace browsing.
     *
     * Registered outside the authenticated group, so nothing here may depend on
     * who is asking: a `my_offers` or moderation branch added to this method
     * would silently never run, and a caller would be shown the published list
     * while believing they were shown their own. Sellers and moderators get
     * `mine()` and `moderationQueue()` below, which are authenticated and
     * permission-gated by route middleware.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->queries->marketPage($request),
            'تم استرجاع العروض بنجاح'
        );
    }

    /**
     * The promoted strip at the top of the market.
     *
     * Not a filter on `index()`: a carousel is a bounded, separately-ordered
     * read, and folding it into the paged list would either paginate the strip or
     * let a caller ask for `featured=true` with their own sort and get a
     * promotion ranked by price.
     *
     * How wide the strip is, and which listings may be in it at all, is
     * `OfferQueries`' answer — the ceiling is a platform setting and a pin is
     * never a pass: a promoted listing that has since sold out, paused, expired
     * or had its seller suspended drops out of this response on its own.
     */
    public function featured(Request $request): JsonResponse
    {
        $page = $this->queries->featuredOffers($request);

        return ApiResponse::success([
            'offers'         => $page['offers'],
            'limit'          => $page['limit'],
            'total_featured' => $page['total_featured'],
        ], 'تم استرجاع العروض المميزة');
    }

    /**
     * Pin or un-pin one listing for that strip. A platform act.
     *
     * Gated on `offers.feature` at the route, and held by no business standing —
     * the same separation as `offers.moderate`: a company deciding what every
     * buyer sees first is not moderating, it is buying the front page with
     * someone else's authority.
     *
     * Which listings may carry a pin, and what the pin writes, is
     * `OfferFeaturingService`'s answer; this method asks the HTTP questions and
     * phrases the result.
     */
    public function feature(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'featured' => ['required', 'boolean'],
        ]);

        try {
            $offer = $this->featuring->feature($id, $validated['featured'], $request->user());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $offer->refresh()->loadMissing('featuredBy');

        return ApiResponse::success(
            OfferResource::make($offer)->resolve($request),
            $validated['featured'] ? 'تم تمييز العرض' : 'أُزيل التمييز عن العرض'
        );
    }

    /**
     * The seller's own shelf: their organization's offers in every status.
     *
     * Scoped by the resolved tenant, not by a parameter, so a request cannot ask
     * for another business's drafts.
     */
    public function mine(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error(
                'مطلوب منشأة نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($request->filled('status')) {
            $request->validate(['status' => ['required', Rule::in(Offer::STATUSES)]]);
        }

        return ApiResponse::paginated(
            $this->queries->shelfPage($orgId, $request),
            'تم استرجاع العروض بنجاح'
        );
    }

    /**
     * The moderation queue.
     *
     * Reaching another business's unpublished offer is a platform act, so this
     * lives behind `offers.moderate` at the route, not behind a parameter here.
     * Defaults to what is actually waiting on a reviewer.
     */
    public function moderationQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Offer::STATUSES)],
        ]);

        $status = $validated['status'] ?? Offer::STATUS_PENDING_REVIEW;

        $page = $this->queries->queuePage($status, $request, fn (Offer $offer) => [
            'moderation' => $this->moderationContext->forOffer($offer),
        ]);

        return ApiResponse::paginated($page, 'تم استرجاع العروض بنجاح');
    }

    /**
     * Geographical discovery: active offers within a radius of given coordinates.
     *
     * Supports all GeoDiscoveryService filters:
     *   radius_km, category_id, opportunity_reason, min_price, max_price,
     *   min_quantity, max_moq, currency_code, delivery_terms, sort_by, per_page
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate(OfferInputRules::nearby());

        $search = $this->geoService->search($validated);

        return ApiResponse::success($search, 'تم استرجاع العروض القريبة بنجاح');
    }

    /**
     * Retrieves full offer details including product, seller, warehouse, and items.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $offer = $this->queries->detail($id);

        // A draft, a listing under review, or one the seller paused is the
        // seller's own business: its price and quantity are not HASSAL's to
        // publish. 404 rather than 403, because "this offer exists but you
        // cannot see it" is itself a leak when ids are sequential.
        //
        // This route is public, so the auth and tenant middleware have not run
        // and `$request->user()` would be null even for a caller holding a
        // valid token — the owner would be refused their own listing. The
        // bearer identity is resolved explicitly instead.
        $viewer = $request->user('sanctum');

        // `User::belongsToOrganization()` is the same question the offer resource
        // asks before it prints a seller's name; one wording, one answer.
        $isOwner = $viewer !== null
            && $viewer->belongsToOrganization((int) $offer->seller_organization_id);

        if (!$isOwner && (!$offer->isPubliclyVisible() || !$offer->sellerMayTrade())) {
            return ApiResponse::error(
                'العرض غير متوفر',
                'NOT_FOUND',
                Response::HTTP_NOT_FOUND
            );
        }

        return ApiResponse::success(
            OfferResource::make($offer)->resolve($request),
            'تم استرجاع تفاصيل العرض بنجاح'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTHENTICATED / SELLER ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Creates a new B2B opportunity offer (Seller organization).
     *
     * Lifecycle:
     *   - If platform setting `offer.requires_review` is true → PENDING_REVIEW
     *   - Otherwise → ACTIVE immediately
     *
     * After creation, the Matching Engine runs in the background to notify
     * buyers with matching open Demands.
     */
    public function store(Request $request): JsonResponse
    {
        $sellerOrgId = TenantContext::id();
        if (!$sellerOrgId) {
            return ApiResponse::error(
                'مطلوب منشأة بائع نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate(OfferInputRules::store());

        try {
            $offer = $this->writing->create($sellerOrgId, $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        // Publishing is what made the offer a commercial fact, so it is also the
        // moment the matching engine and the market hear about it.
        $this->lifecycle->announcePublication($offer);

        $message = match ($offer->status) {
            Offer::STATUS_DRAFT => 'تم حفظ العرض كمسودة',
            Offer::STATUS_PENDING_REVIEW => 'تم تقديم العرض وهو الآن قيد المراجعة',
            default => 'تم نشر العرض بنجاح',
        };

        return ApiResponse::success(
            $offer->load(['seller', 'warehouse', 'variant.product']),
            $message,
            Response::HTTP_CREATED
        );
    }

    /**
     * Edit a listing the seller still controls.
     *
     * What may not be edited, and why, is the writing service's answer; this
     * method only asks the HTTP questions: which listing, and what shape is a
     * legal field.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        try {
            $this->writing->assertEditable($offer);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $validated = $request->validate(OfferInputRules::update());

        try {
            $offer = $this->writing->update($offer, $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($offer->fresh(), 'تم تحديث العرض');
    }

    /**
     * Put a draft in front of the platform (or straight on the shelf).
     *
     * This is the step that makes a returned offer recoverable; the guards and
     * the consequences are the lifecycle service's.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        try {
            $result = $this->lifecycle->submit($offer);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $result['offer']->fresh(),
            $result['requires_review'] ? 'تم تقديم العرض للمراجعة' : 'تم نشر العرض بنجاح'
        );
    }

    /**
     * Discard a draft.
     *
     * Only a DRAFT can be deleted, and only by its seller — the second is
     * `ownedOffer()` above, the first is the lifecycle service's refusal.
     */
    public function destroy(int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        try {
            $this->lifecycle->discard($offer);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(['id' => $offer->id], 'تم حذف المسودة');
    }

    /**
     * The seller's own control over an offer they published.
     *
     * The route gates this on `offers.publish`, which covers pausing and
     * resuming. Cancelling is withdrawn-only under `offers.cancel`: a staff
     * account that may tidy the shelf must not be able to erase a listing the
     * business is still trading under. That check is authorization, so it is
     * asked here; which transitions the offer itself allows is the service's
     * answer.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        $validated = $request->validate([
            // The states a seller may reach by hand are the lifecycle machine's
            // answer, not a list retyped at the door.
            'status' => ['required', Rule::in($this->lifecycle->sellerTransitionTargets())],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['status'] === Offer::STATUS_CANCELLED && !$request->user()->can('offers.cancel')) {
            return ApiResponse::error(
                'سحب العرض المنشور يتطلب صلاحية إلغاء العروض',
                'PERMISSION_DENIED',
                Response::HTTP_FORBIDDEN,
                ['required_permission' => 'offers.cancel']
            );
        }

        try {
            $offer = $this->lifecycle->transition($offer, $validated['status']);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($offer->fresh(), 'تم تحديث حالة العرض بنجاح');
    }

    /**
     * The platform's moderation decision on a submitted offer.
     *
     * Review is meaningless if the only way out of PENDING_REVIEW is for the
     * seller to publish themselves, so this is a separate action with a
     * separate gate; what approving and returning do — to the row, to the
     * matching engine, to the seller's notice box — is `review()` in the
     * lifecycle service. It never edits the offer's commercial terms: the
     * platform moderates, it does not sell.
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $offer = Offer::findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(Offer::REVIEW_DECISIONS)],
            'reason' => ['required_if:decision,'.Offer::REVIEW_RETURN, 'nullable', 'string', 'max:500'],
        ]);

        try {
            $offer = $this->lifecycle->review($offer, $validated['decision'], $validated['reason'] ?? null);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $offer->fresh(),
            $validated['decision'] === Offer::REVIEW_APPROVE
                ? 'نُشر العرض بعد الموافقة عليه'
                : 'أُعيد العرض إلى البائع ليصلحه'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED HTTP PLUMBING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load an offer and confirm the organization in context owns it.
     *
     * Ownership is answered here rather than per action because every seller
     * write must ask it, and the first version of this controller asked it
     * differently in each method — which is how one of them ended up not asking.
     */
    protected function ownedOffer(int $id): Offer|JsonResponse
    {
        $sellerOrgId = TenantContext::id();
        if (!$sellerOrgId) {
            return ApiResponse::error('مطلوب منشأة بائع نشطة', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $offer = Offer::find($id);
        if (!$offer || (int) $offer->seller_organization_id !== (int) $sellerOrgId) {
            return ApiResponse::error(
                'غير مصرح لك بتعديل هذا العرض',
                'UNAUTHORIZED_OFFER_ACTION',
                Response::HTTP_FORBIDDEN
            );
        }

        return $offer;
    }
}
