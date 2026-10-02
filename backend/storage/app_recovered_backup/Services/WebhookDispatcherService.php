<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\OutboundEndpointPolicy;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookDispatcherService
{
    /**
     * The events this installation actually emits.
     *
     * A subscription is a promise about traffic, so the list is the traffic: an
     * endpoint registered for anything else sits there receiving nothing, and the
     * business that wired its warehouse system to it concludes the platform is
     * broken. Add an event here in the same change that dispatches it, and the
     * registration route will accept it without a second edit.
     */
    public const EVENTS = [
        'deal.status_changed',
        'inventory.reserved',
        'inventory.released',
    ];

    /**
     * Dispatches an event payload to all subscribed webhooks for an organization or system.
     */
    public function dispatch(string $eventName, array $payload, ?int $organizationId = null): int
    {
        $query = Webhook::where('is_active', true);
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $webhooks = $query->get();
        $dispatchedCount = 0;

        foreach ($webhooks as $webhook) {
            $events = $webhook->events ?? [];
            if (!in_array('*', $events, true) && !in_array($eventName, $events, true)) {
                continue;
            }

            $this->sendDelivery($webhook, $eventName, $payload);
            $dispatchedCount++;
        }

        return $dispatchedCount;
    }

    /**
     * Signs a delivery. The timestamp is part of the signed material so a
     * captured payload cannot be replayed against a receiver that checks it.
     */
    public function sign(int $timestamp, string $jsonPayload, string $secret): string
    {
        return hash_hmac('sha256', "t={$timestamp}.{$jsonPayload}", $secret);
    }

    /**
     * Verifies a signature presented by this platform, with a replay window.
     */
    public function verify(string $header, string $jsonPayload, string $secret, int $toleranceSeconds = 300): bool
    {
        $timestamp = null;
        $provided = null;
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't') { $timestamp = (int) $value; }
            if ($key === 'v1') { $provided = $value; }
        }

        if ($timestamp === null || $provided === null) {
            return false;
        }
        if (abs(Carbon::now()->timestamp - $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($timestamp, $jsonPayload, $secret), $provided);
    }

    /**
     * Sends webhook payload with HMAC-SHA256 signature and records delivery status.
     */
    public function sendDelivery(Webhook $webhook, string $eventName, array $payload): WebhookDelivery
    {
        $eventId = 'evt_' . Str::random(24);
        $timestamp = Carbon::now()->timestamp;

        $wrappedPayload = [
            'id' => $eventId,
            'event' => $eventName,
            'timestamp' => $timestamp,
            'data' => $payload,
        ];

        $jsonPayload = json_encode($wrappedPayload);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event_name' => $eventName,
            'event_id' => $eventId,
            'payload' => $wrappedPayload,
            'signature' => $this->sign($timestamp, $jsonPayload, $webhook->secret),
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempt' => 1,
        ]);

        return $this->attemptDelivery($webhook, $delivery);
    }

    /**
     * Re-sends a delivery that already exists, updating that same row.
     *
     * This must not go through sendDelivery(): creating a fresh row per retry
     * turned one failing endpoint into a fan-out, because every generated row
     * was itself eligible for five retries while the original row's attempt
     * counter kept climbing. One event stayed one event.
     *
     * The event id is kept so a receiver that de-duplicates on it still sees
     * one event. The timestamp is not: the signature covers it, and a receiver
     * honouring the replay window would reject a minute-old (or hour-old) one.
     */
    public function retryDelivery(WebhookDelivery $delivery): WebhookDelivery
    {
        $webhook = $delivery->webhook;

        if (!$webhook) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'last_error' => 'The webhook was deleted; this delivery cannot be retried.',
            ]);

            return $delivery;
        }

        $delivery->increment('attempt');

        return $this->attemptDelivery($webhook, $delivery);
    }

    /**
     * Performs one HTTP attempt for a stored delivery and records its outcome
     * on that row.
     */
    protected function attemptDelivery(Webhook $webhook, WebhookDelivery $delivery): WebhookDelivery
    {
        $timestamp = Carbon::now()->timestamp;

        $wrappedPayload = is_array($delivery->payload) ? $delivery->payload : [];
        $wrappedPayload['id'] = $wrappedPayload['id'] ?? $delivery->event_id;
        $wrappedPayload['event'] = $wrappedPayload['event'] ?? $delivery->event_name;
        $wrappedPayload['timestamp'] = $timestamp;

        $jsonPayload = json_encode($wrappedPayload);
        $signature = $this->sign($timestamp, $jsonPayload, $webhook->secret);
        $eventName = (string) $delivery->event_name;

        $delivery->update([
            'payload' => $wrappedPayload,
            'signature' => $signature,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        // Checked here as well as at registration: a row created before the
        // policy existed, or a hostname whose DNS answer changed, would
        // otherwise be delivered to an internal address with a valid
        // signature and a tenant's event data inside it.
        if (!OutboundEndpointPolicy::accepts($webhook->endpoint_url)) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_BLOCKED,
                'last_error' => 'Endpoint blocked by the outbound address policy.',
            ]);
            $webhook->increment('failure_count');

            return $delivery;
        }

        try {
            $response = Http::timeout((int) config('hassal.webhooks.timeout_seconds', 10))
                // A redirect turns "we validated this URL" into "we posted to
                // whatever that URL points at next", so the receiver has to
                // accept the payload where it was registered.
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Hassal-Signature' => "t={$timestamp},v1={$signature}",
                    'X-Hassal-Event' => $eventName,
                    'User-Agent' => 'Hassal-Webhook-Engine/2.0',
                ])
                ->post($webhook->endpoint_url, $wrappedPayload);

            $isSuccess = $response->successful();

            $delivery->update([
                'status' => $isSuccess ? WebhookDelivery::STATUS_DELIVERED : WebhookDelivery::STATUS_FAILED,
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 1000),
                'delivered_at' => $isSuccess ? Carbon::now() : null,
                'last_error' => $isSuccess ? null : "HTTP status {$response->status()}",
            ]);

            if ($isSuccess) {
                $webhook->update([
                    'failure_count' => 0,
                    'last_triggered_at' => Carbon::now(),
                ]);
            } else {
                $webhook->increment('failure_count');
            }
        } catch (Exception $e) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);
            $webhook->increment('failure_count');
        }

        return $delivery;
    }
}
