<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\TenantContext;
use App\Services\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            'endpoint_url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array'],
        ]);

        $webhook = Webhook::create([
            'organization_id' => $orgId,
            'endpoint_url' => $validated['endpoint_url'],
            'secret' => 'whsec_' . Str::random(32),
            'events' => $validated['events'],
            'is_active' => true,
        ]);

        return ApiResponse::success($webhook, 'Webhook endpoint registered successfully', Response::HTTP_CREATED);
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
