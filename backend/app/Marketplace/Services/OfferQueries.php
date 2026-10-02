<?php

namespace App\Marketplace\Services;

use App\Marketplace\Models\Offer;
use App\Community\Models\OfferComment;
use App\Marketplace\Resources\OfferResource;
use App\Platform\Services\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * How listings are read, in one place.
 *
 * The three list endpoints (market, shelf, moderation queue) and the carousel
 * all start here, because each copy of the visibility chain or the ordering
 * rules is a future disagreement between two screens showing "the offers".
 */
class OfferQueries
{
    public function __construct(
        protected SystemSettingsService $settings,
    ) {}

    /**
     * How many listings the market puts in front of every visitor.
     *
     * A platform setting (`marketplace.featured_limit`) rather than a client
     * parameter, because the number of listings promoted to everyone is the
     * platform's decision — and a row an operator mis-typed must not turn the
     * front page into a full scan of the shelf. The bounds are the strip's, not
     * the setting's: zero would render nothing and a thousand would render a
     * page nobody scrolls.
     */
    public function featuredLimit(): int
    {
        return max(1, min(30, (int) $this->settings->get('marketplace.featured_limit', 8)));
    }

    protected function baseQuery(): Builder
    {
        return Offer::with(['seller', 'warehouse', 'variant.product.brand', 'variant.product.images', 'variant.product.category'])
            // Both figures are counted from their rows on every read. Nothing in
            // this schema stores an engagement total, so a listing cannot
            // display a number that outlived the evidence behind it.
            ->withCount('likes')
            // …and the comment count is only ever the published thread: a
            // question held for review is not public information, and counting
            // it would advertise that something exists without showing it.
            ->withCount(['comments as published_comments_count' => fn ($q) => $q->where('status', OfferComment::STATUS_PUBLISHED)]);
    }

    /**
     * One listing, read whole.
     *
     * The detail page needs what a card needs plus the line items, and it is
     * asked here rather than written out at the endpoint so the market, the
     * shelf, the queue and the page cannot drift into four different ideas of
     * what "the offer" contains. The counts come along because the page prints
     * them and a detail read without them would show zero likes beside a
     * listing that has twenty.
     */
    public function detail(int $id): Offer
    {
        return $this->baseQuery()->with('items')->findOrFail($id);
    }

    /**
     * The rows a stranger to the platform may be shown, in one place.
     *
     * Published, in stock, not past its own expiry date, and offered by a
     * business the platform has not withdrawn. No caller may widen this from the
     * request.
     *
     * It is a method rather than four lines in `index()` because the carousel
     * applies the identical rule here too, and `GeoDiscoveryService` applies a
     * third copy of it in SQL. Every listing path that forgets the expiry bound
     * shows a finished price among the live ones — the exact failure
     * tests/Feature/NearbyOffersTest.php exists to catch, which is why both
     * callers now read the same chain instead of paraphrasing it.
     */
    protected function restrictToPublicMarket(Builder $query): void
    {
        $query->tradable()
              ->where('status', Offer::STATUS_ACTIVE)
              ->where('available_quantity', '>', 0)
              ->where(function ($q) {
                  $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
              });
    }

    /**
     * Shared browsing filters. Deliberately contains no visibility rule —
     * which rows a caller may see is decided by the calling endpoint.
     */
    protected function applyFilters(Builder $query, Request $request): void
    {
        // Category filter. The offer does not hold a category: this is the
        // product's, reached through the variant that names it, so a listing
        // that is filtered into a category is in it because its goods are.
        if ($request->filled('category_id')) {
            $query->whereHas(
                'variant.product',
                fn ($q) => $q->where('category_id', $request->integer('category_id'))
            );
        }

        // Opportunity reason filter
        if ($request->filled('opportunity_reason')) {
            $query->where('opportunity_reason', $request->input('opportunity_reason'));
        }

        // City filter via warehouse
        if ($request->filled('city')) {
            $city = $request->input('city');
            $query->whereHas('warehouse', fn ($w) => $w->where('city', 'like', "%{$city}%"));
        }

        // Price range
        if ($request->filled('min_price')) {
            $query->where('unit_price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('unit_price', '<=', (float) $request->input('max_price'));
        }

        // MOQ filter
        if ($request->filled('max_moq')) {
            $query->where('min_order_quantity', '<=', (float) $request->input('max_moq'));
        }

        // Currency filter
        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper($request->input('currency_code')));
        }

        // Delivery terms
        if ($request->filled('delivery_terms')) {
            $query->where(function ($q) use ($request) {
                $q->where('delivery_terms', $request->input('delivery_terms'))
                  ->orWhere('delivery_terms', 'BOTH');
            });
        }

        // Full-text search (title + product name + SKU)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                  ->orWhere('title_en', 'like', "%{$search}%")
                  ->orWhereHas('variant.product', fn ($p) =>
                      $p->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                  );
            });
        }
    }

    /**
     * The market's own page: what a stranger may see, filtered and ordered.
     */
    public function marketPage(Request $request): LengthAwarePaginator
    {
        $query = $this->baseQuery();
        $this->restrictToPublicMarket($query);
        $this->applyFilters($query, $request);

        return $this->paginate($query, $request);
    }

    /**
     * The seller's shelf, in every status — the visibility chain deliberately
     * not applied, because a business may always see its own drafts.
     */
    public function shelfPage(int $organizationId, Request $request): LengthAwarePaginator
    {
        $query = $this->baseQuery()->where('seller_organization_id', $organizationId);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $this->applyFilters($query, $request);

        return $this->paginate($query, $request);
    }

    /**
     * The moderation queue, with the reviewer's dossier merged into each row.
     *
     * @param  (callable(Offer): array<string, mixed>)|null  $decorate
     */
    public function queuePage(string $status, Request $request, ?callable $decorate = null): LengthAwarePaginator
    {
        $query = $this->baseQuery()
            ->with(['seller.documents.reviewer:id,name', 'seller.members:id,name', 'variant.product.images'])
            ->where('status', $status);

        $this->applyFilters($query, $request);

        return $this->paginate($query, $request, $decorate);
    }

    /**
     * The promoted strip: the public chain first, then the pin.
     *
     * The size of the strip is decided here rather than passed in, because it is
     * a property of the strip — see `featuredLimit()`. It comes back with the
     * rows so the client renders the ceiling it was actually given instead of
     * assuming one, and `total_featured` with it so an operator can be told the
     * market is showing the newest eight of the nine pinned.
     *
     * @return array{offers: array<int, array<string, mixed>>, limit: int, total_featured: int}
     */
    public function featuredOffers(Request $request): array
    {
        $limit = $this->featuredLimit();

        $query = $this->baseQuery();
        $this->restrictToPublicMarket($query);

        $offers = $query->featured()
            ->limit($limit)
            ->get()
            ->map(fn (Offer $offer) => OfferResource::make($offer)->resolve($request))
            ->all();

        // Counted with the same visibility chain, not `whereNotNull` alone: a
        // stale pin on a sold-out listing is not something to show as a promotion
        // the market has lost, because the market is not supposed to show it.
        $pinnable = $this->baseQuery();
        $this->restrictToPublicMarket($pinnable);

        return [
            'offers'         => $offers,
            'limit'          => $limit,
            'total_featured' => $pinnable->whereNotNull('featured_at')->count(),
        ];
    }

    /**
     * Sorting and paging, shared so the three listings order identically.
     *
     * `$decorate` lets one listing (the moderation queue) attach reviewer
     * context without forking a second copy of the ordering and paging rules
     * that would drift from this one.
     *
     * @param  (callable(Offer): array<string, mixed>)|null  $decorate
     */
    protected function paginate(Builder $query, Request $request, ?callable $decorate = null): LengthAwarePaginator
    {
        // Sorting. The client names an intent (`newest`, `cheapest`); the
        // column it means lives here, in one place, so a UI rename cannot
        // quietly turn ordering into "unrecognised, use created_at".
        $intentColumns = [
            'newest'     => ['created_at', 'desc'],
            'cheapest'   => ['unit_price', 'asc'],
            'priciest'   => ['unit_price', 'desc'],
            'expiring'   => ['expires_at', 'asc'],
            'quantity'   => ['available_quantity', 'desc'],
            'nearest_to_expiry' => ['expires_at', 'asc'],
        ];
        $sortableColumns = ['unit_price', 'created_at', 'expires_at', 'available_quantity'];

        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        if (isset($intentColumns[$sortBy])) {
            [$column, $direction] = $intentColumns[$sortBy];
            $query->orderBy($column, $direction);
        } elseif (in_array($sortBy, $sortableColumns, true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = min(50, max(1, (int) $request->input('per_page', 15)));

        return $query->paginate($perPage)->through(function (Offer $offer) use ($decorate, $request) {
            // Resolved here rather than handed over as a resource with
            // `additional()`: this envelope encodes the items itself
            // (`ApiResponse::paginated`), and a resource's additional data is
            // only merged when that resource builds its own response — so a
            // decorated key would reach the client as nothing at all. The
            // moderation queue is exactly that case, and the reviewer's dossier
            // is the whole reason it exists.
            $data = OfferResource::make($offer)->resolve($request);

            return $decorate ? array_merge($data, $decorate($offer)) : $data;
        });
    }
}
