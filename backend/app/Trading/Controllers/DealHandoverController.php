<?php

namespace App\Trading\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Trading\Services\DealHandoverService;
use App\Trading\Services\DealStateMachineService;
use App\Core\Tenant\TenantContext;
use App\Trading\Support\HandoverRefusal;
use App\Core\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handover code of one order: what it is, and the scan that spends it.
 *
 * Two routes, and the split between them is the whole design. Reading a code is
 * a party act with no side effect, so it sits behind `deals.view` — the buyer
 * needs to show it at the gate and the seller needs to read it back. Spending it
 * changes what the platform will accept as a delivered order, so it needs
 * `deals.deliver`, an idempotency key, and the answer of one service that nobody
 * else can talk to directly.
 */
class DealHandoverController extends Controller
{
    public function __construct(protected DealHandoverService $handovers) {}

    /**
     * GET /api/v1/deals/{id}/handover
     */
    public function show(int $id): JsonResponse
    {
        $deal = $this->authorizedDeal($id);

        if ($deal instanceof JsonResponse) {
            return $deal;
        }

        $handover = $this->handovers->forDeal($deal, request()->user()?->id);

        if ($handover === null) {
            // Not a 404 dressed up as a business rule: there genuinely is no
            // code until the seller accepts the order, and the client needs to
            // say "the order has not been accepted yet" rather than show a blank
            // QR box and let the operator guess what is missing.
            return ApiResponse::error(
                'No handover code has been issued for this order yet; it is issued when the seller accepts it.',
                HandoverRefusal::NOT_ISSUED,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success($this->payloadFor($deal, $handover), 'Handover code retrieved successfully');
    }

    /**
     * POST /api/v1/deals/{id}/handover/verify
     *
     * The scan. Whatever the caller used — a camera on a printed label, a
     * barcode gun, ten characters typed under a bad light — arrives as `code`,
     * and the service decides what it means.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $deal = $this->authorizedDeal($id);

        if ($deal instanceof JsonResponse) {
            return $deal;
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $handover = $this->handovers->verify(
                $deal,
                $validated['code'],
                $request->user(),
                TenantContext::id(),
                $validated['notes'] ?? null
            );
        } catch (HandoverRefusal $refused) {
            return ApiResponse::error(
                $refused->getMessage(),
                $refused->refusalCode,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(
            $this->payloadFor($deal->refresh(), $handover) + [
                'deal_status' => $deal->status,
                // The next step is named because the scan does not close the
                // order — the buyer's confirmation does — and a driver who
                // believes the delivery is finished writes a different story in
                // their own notebook than the platform is holding.
                'next_step' => 'buyer_confirms_receipt',
            ],
            'Handover verified. The buyer can now confirm receipt.',
            Response::HTTP_CREATED
        );
    }

    /**
     * GET /api/v1/deals/{id}/handover/label
     *
     * The same code as a symbol, for the one moment a code has to exist before a
     * phone is anywhere near it: the label printed on the box that leaves the
     * warehouse. SVG rather than a raster because it is this platform's own
     * encoder's output at any size — a thermal receipt and a wall-sized sheet are
     * the same bytes — and because drawing squares needs no image extension on a
     * server that has none installed.
     *
     * Two things make this route an exception, deliberately. It answers with the
     * document rather than the API's envelope, so an `<img>` shows it and a
     * browser prints it without a client parsing JSON first; and a refusal still
     * arrives as the usual JSON body, because a picture that says "forbidden" is
     * a picture nobody scans.
     */
    public function label(int $id): Response
    {
        $deal = $this->authorizedDeal($id);

        if ($deal instanceof JsonResponse) {
            return $deal;
        }

        $handover = $this->handovers->forDeal($deal, request()->user()?->id);

        if ($handover === null) {
            return ApiResponse::error(
                'No handover code has been issued for this order yet; it is issued when the seller accepts it.',
                HandoverRefusal::NOT_ISSUED,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $svg = QrCode::svg($handover->payload($deal));

        if ($svg === null) {
            return ApiResponse::error(
                'This order\'s handover payload is too long to encode as a symbol.',
                'HANDOVER_LABEL_TOO_LARGE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // `no-store` because a printed label outlives the session that printed
        // it, and a cached copy of someone else's order number should not be
        // what a shared office browser hands back to the next person at the
        // printer.
        return (new \Illuminate\Http\Response($svg, Response::HTTP_OK))
            ->header('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->header('Content-Disposition', 'inline; filename="hassal-handover-' . $deal->deal_number . '.svg"')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return Deal|JsonResponse  the order, or the error that replaces it
     */
    protected function authorizedDeal(int $id): Deal|JsonResponse
    {
        $orgId = TenantContext::id();
        $user = request()->user();

        $deal = Deal::find($id);

        if (!$deal) {
            return ApiResponse::error('Deal not found', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $isParty = (int) $orgId === (int) $deal->buyer_organization_id
            || (int) $orgId === (int) $deal->seller_organization_id;

        if (!$user?->isPlatformOperator() && !$isParty) {
            return ApiResponse::error(
                'Unauthorized to access this deal',
                'DEAL_ACCESS_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }

        return $deal;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFor(Deal $deal, DealHandover $handover): array
    {
        $payload = $handover->payload($deal);

        return [
            'deal_id' => $deal->id,
            'deal_number' => $deal->deal_number,
            'code' => $handover->code,
            'qr_payload' => $payload,
            // The symbol itself, as the grid of modules the payload encodes, so
            // a screen can *show* a code rather than ask somebody to read one.
            // Every client draws from this and none of them encodes: three
            // implementations of a QR standard is three chances for a label and
            // a screen to disagree about the same order. Null when a payload
            // would need a bigger symbol than this platform builds (§4.7) — the
            // characters above are still the code, and a client that gets null
            // shows those rather than an empty box.
            'qr' => QrCode::tryEncode($payload),
            'status' => $handover->status,
            'issued_at' => $handover->issued_at?->toIso8601String(),
            'verified_at' => $handover->verified_at?->toIso8601String(),
            'verified_by_organization_id' => $handover->verified_by_organization_id,
            'verification_notes' => $handover->verification_notes,
            'deal_status' => $deal->status,
            'verifiable' => in_array($deal->status, DealStateMachineService::handoverEligibleStatuses(), true)
                && $handover->isActive(),
        ];
    }
}
