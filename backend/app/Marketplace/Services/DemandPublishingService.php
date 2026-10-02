<?php

namespace App\Marketplace\Services;

use App\Core\Exceptions\ApiRefused;
use App\Marketplace\Models\Demand;
use App\Platform\Models\Currency;
use App\Platform\Services\SystemSettingsService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publishing one buyer request for quotation, from the form to the board.
 *
 * Two platform decisions belong here rather than in the controller: which
 * currency the request is priced in when the buyer does not name one, and how
 * long the request stays open when the buyer does not say. Both are settings an
 * operator can move, so a hardcoded default in a controller would be a second,
 * invisible configuration — and the notice period is the one number that
 * decides whether a seller who checks on Monday still has a live RFQ.
 *
 * Matching is part of publishing, not a follow-up the caller remembers: a
 * demand that reached the table without being looked at would sit unseen until
 * somebody opened the matches screen, and the whole point of an RFQ is that the
 * platform starts looking for goods the moment the buyer stops typing.
 */
class DemandPublishingService
{
    public function __construct(
        protected SystemSettingsService $settings,
        protected DemandMatchingService $matching,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public function publish(array $validated, int $buyerOrgId, int $buyerUserId): Demand
    {
        $expiryDays = (int) $this->settings->get('demand.expiry_days', 14);
        $defaultCurrency = strtoupper((string) $this->settings->get('platform.default_currency'));
        $currencyCode = strtoupper($validated['currency_code'] ?? $defaultCurrency);

        if (!Currency::isActive($currencyCode)) {
            throw new ApiRefused(
                'العملة المحددة غير موجودة أو غير مفعلة',
                'CURRENCY_NOT_ACTIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $demand = Demand::create([
            'demand_number'         => 'RFQ-' . date('ymd') . '-' . strtoupper(Str::random(6)),
            'buyer_organization_id' => $buyerOrgId,
            'buyer_user_id'         => $buyerUserId,
            'category_id'           => $validated['category_id'] ?? null,
            'title_ar'              => trim($validated['title_ar']),
            'title_en'              => isset($validated['title_en']) ? trim($validated['title_en']) : null,
            'description'           => $validated['description'] ?? null,
            'target_quantity'       => (float) $validated['target_quantity'],
            'unit'                  => trim($validated['unit']),
            'target_unit_price'     => isset($validated['target_unit_price']) ? (float) $validated['target_unit_price'] : null,
            'currency_code'         => $currencyCode,
            'target_delivery_date'  => $validated['target_delivery_date'] ?? null,
            'delivery_city'         => trim($validated['delivery_city']),
            'delivery_latitude'     => $validated['delivery_latitude'] ?? null,
            'delivery_longitude'    => $validated['delivery_longitude'] ?? null,
            'special_requirements'  => $validated['special_requirements'] ?? null,
            'status'                => Demand::STATUS_OPEN,
            'expires_at'            => $validated['expires_at'] ?? Carbon::now()->addDays($expiryDays),
        ]);

        // Matching is a reader of the demand, never its owner: it can fail on a
        // slow vector or a malformed coordinate, and an RFQ the buyer already
        // published must not vanish because the engine that scores it had a bad
        // minute. The log names the demand so the matches screen can be re-run
        // by hand — which it is, on every read.
        try {
            $this->matching->matchDemand($demand);
        } catch (Exception $e) {
            Log::error('Demand matching failed after demand creation', ['demand_id' => $demand->id, 'exception' => $e]);
        }

        return $demand;
    }
}
