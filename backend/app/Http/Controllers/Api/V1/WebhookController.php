<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Rules\SafeWebhookEndpoint;
use App\Services\TenantContext;
use App\Services\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookDispatcherService $dispatcher
    ) {}

    public function index(): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $webhooks = Webhook::where('organization_id', $orgId)->withCount('deliveries')->get();
        return ApiResponse::success($webhooks, 'Webhooks retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            // The endpoint is not a display value: this server posts to it from
            // inside its own network, so an internal address here is a request
            // forgery primitive rather than a typo. See OutboundEndpointPolicy.
            'endpoint_url' => ['required', 'string', 'max:500', new SafeWebhookEndpoint],
            'events' => ['required', 'array', 'min:1', 'max:25'],
            'events.*' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)*$/i'],
        ]);

        // 32 bytes from the CSPRNG, so the HMAC key carries the same entropy as
        // the hash it feeds. The prefix exists so a string that turns up in a
        // log is instantly recognisable as a webhook secret.
        $secret = 'whsec_' . bin2hex(random_bytes(32));

        $webhook = Webhook::create([
            'organization_id' => $orgId,
            'endpoint_url' => $validated['endpoint_url'],
            'secret' => $secret,
            'events' => array_values(array_unique($validated['events'])),
            'is_active' => true,
        ]);

        // `secret` is on the model's $hidden list, so it can never be read back
        // through GET /webhooks — which also means this response is the only
        // time the tenant ever sees it. Deliveries are signed with it, so a
        // receiver that misses this value cannot prove a payload came here.
        $payload = $webhook->only([
            'id', 'organization_id', 'endpoint_url', 'events', 'is_active', 'failure_count', 'created_at',
        ]);
        $payload['secret'] = $secret;

        return ApiResponse::success(
            $payload,
            'Webhook endpoint registered successfully. Store the secret now: it will not be shown again.',
            Response::HTTP_CREATED
        );
    }

    public function ping(int $id): JsonResponse
    {
        $orgId = TenantContext::id();
        $webhook = Webhook::where('organization_id', $orgId)->findOrFail($id);

        $delivery = $this->dispatcher->sendDelivery($webhook, 'system.ping', [
            'message' => 'Hassal webhook ping test',
            'timestamp' => now()->toIso8601String(),
        ]);

        return ApiResponse::success($delivery, 'Webhook ping executed');
    }

    public function deliveries(int $id): JsonResponse
    {
        $orgId = TenantContext::id();
        $webhook = Webhook::where('organization_id', $orgId)->findOrFail($id);

        $deliveries = WebhookDelivery::where('webhook_id', $webhook->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return ApiResponse::paginated($deliveries, 'Webhook deliveries history retrieved');
    }
}
