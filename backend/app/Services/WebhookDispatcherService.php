<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookDispatcherService
{
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
        $signature = hash_hmac('sha256', "t={$timestamp}." . $jsonPayload, $webhook->secret);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event_name' => $eventName,
            'event_id' => $eventId,
            'payload' => $wrappedPayload,
            'signature' => $signature,
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempt' => 1,
        ]);

        try {
            $response = Http::timeout(5)
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
