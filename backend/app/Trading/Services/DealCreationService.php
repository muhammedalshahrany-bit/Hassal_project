<?php

namespace App\Trading\Services;

use App\Marketplace\Models\Offer;
use App\Notifications\Services\NotificationManager;
use App\Platform\Services\SystemSettingsService;
use App\Core\Exceptions\ApiRefused;
use App\Trading\Models\Deal;
use App\Trading\Models\DealItem;
use App\Trading\Models\PurchaseRequest;
use App\Trading\Models\Reservation;
use App\Trading\Support\DealNotices;
use App\Identity\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opening a deal against held stock.
 *
 * A deal is born only from a live reservation — the seller's acceptance is
 * what put this stock on hold, and the deal is formed against that hold, never
 * around it. Creating one is therefore a snapshot act in three parts, inside
 * one transaction: the price is frozen as the platform computed it at this
 * moment (currency, rates, tax and fees included, so a later settings change
 * cannot rewrite what both parties agreed to), the goods are copied into a
 * deal item that outlives any edit to the catalogue, and the reservation is
 * marked converted so it cannot open a second deal.
 *
 * The notices go out only after the transaction commits, because a seller
 * told "you have a deal to confirm" about a row that failed to write is a
 * promise the platform cannot keep.
 */
class DealCreationService
{
    public function __construct(
        protected PricingAndCurrencyService $pricingService,
        protected SystemSettingsService $settingsService,
        protected NotificationManager $notificationManager,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ApiRefused
     */
    public function fromReservation(Reservation $reservation, int $buyerOrgId, User $buyerUser, array $validated): Deal
    {
        if ($reservation->isExpired()) {
            throw new ApiRefused(
                'Reservation has expired. Please reserve the inventory again.',
                'RESERVATION_EXPIRED'
            );
        }

        $offer = $reservation->offer;
        $qty = (float) $reservation->quantity;
        $unitPrice = (float) $reservation->unit_price;

        // The seller's acceptance is what put this stock on hold, so the ask
        // behind the reservation — when there was one — is the deal's origin.
        $purchaseRequest = PurchaseRequest::where('reservation_id', $reservation->id)->first();

        $pricing = $this->pricingService->calculateDealPricing($qty, $unitPrice, $offer->currency_code);

        try {
            $deal = DB::transaction(function () use ($reservation, $offer, $buyerOrgId, $buyerUser, $validated, $pricing, $qty, $unitPrice, $purchaseRequest) {
                $dealNumber = 'DEAL-' . date('ymd') . '-' . strtoupper(Str::random(4));

                $newDeal = Deal::create([
                    'deal_number' => $dealNumber,
                    'offer_id' => $offer->id,
                    'reservation_id' => $reservation->id,
                    'purchase_request_id' => $purchaseRequest?->id,
                    'buyer_organization_id' => $buyerOrgId,
                    'seller_organization_id' => $offer->seller_organization_id,
                    'buyer_user_id' => $buyerUser->id,
                    'warehouse_id' => $offer->warehouse_id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'currency_code' => $offer->currency_code,
                    'base_currency' => $pricing['base_currency'],
                    'exchange_rate_snapshot' => $pricing['exchange_rate_snapshot'],
                    'rate_source' => $pricing['rate_source'],
                    'rate_timestamp' => $pricing['rate_timestamp'],
                    'base_amount' => $pricing['base_amount'],
                    'discount_amount' => $pricing['discount_amount'],
                    'tax_rate_snapshot' => $pricing['tax_rate_snapshot'],
                    'tax_amount' => $pricing['tax_amount'],
                    'platform_fee_rate_snapshot' => $pricing['platform_fee_rate_snapshot'],
                    'platform_fee_amount' => $pricing['platform_fee_amount'],
                    'delivery_fee_amount' => $pricing['delivery_fee_amount'],
                    'buyer_total_amount' => $pricing['buyer_total_amount'],
                    'seller_net_amount' => $pricing['seller_net_amount'],
                    'status' => Deal::STATUS_PENDING_SELLER_APPROVAL,
                    'delivery_method' => $validated['delivery_method'],
                    'delivery_address' => $validated['delivery_address'] ?? null,
                    'inspection_window_hours' => $offer->inspection_window_hours ?? $this->settingsService->get('inspection.window', 48),
                ]);

                // The item snapshot outlives the catalogue: a product renamed
                // next week does not rewrite what was bought this week.
                DealItem::create([
                    'deal_id' => $newDeal->id,
                    'product_variant_id' => $offer->product_variant_id,
                    'snapshot_product_name' => $offer->variant->product->name_ar,
                    'snapshot_sku' => $offer->variant->sku,
                    'snapshot_unit_name' => $offer->unit,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal_amount' => $pricing['base_amount'],
                ]);

                // Converted, not released: the hold this deal consumes must
                // never be able to open a second deal.
                $reservation->update([
                    'status' => Reservation::STATUS_CONVERTED_TO_DEAL,
                    'converted_at' => Carbon::now(),
                ]);

                return $newDeal;
            });
        } catch (Exception $e) {
            throw new ApiRefused($e->getMessage(), 'DEAL_CREATION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->announce($deal, $buyerUser);

        return $deal;
    }

    /**
     * Tell both sides what just opened between them.
     *
     * The notice reads from the deal's own snapshot and its two parties, all
     * of which this write just produced and none of which is on the model
     * already loaded.
     */
    private function announce(Deal $deal, User $buyerUser): void
    {
        $deal->load(['items', 'buyerOrganization', 'sellerOrganization', 'offer.seller']);

        $awaitingSeller = DealNotices::awaitingSeller($deal);
        $createdForBuyer = DealNotices::createdForBuyer($deal);

        if ($deal->offer?->seller) {
            $this->notificationManager->notifyOrganization(
                $deal->offer->seller,
                $awaitingSeller['title'],
                $awaitingSeller['body'],
                $awaitingSeller['data'] + ['organization_id' => $deal->seller_organization_id]
            );
        }

        $this->notificationManager->notify(
            $buyerUser,
            $createdForBuyer['title'],
            $createdForBuyer['body'],
            $createdForBuyer['data'] + ['organization_id' => $deal->buyer_organization_id]
        );
    }
}
