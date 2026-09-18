<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcherService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessWebhooksCommand extends Command
{
    protected $signature = 'app:process-webhooks {--limit=50 : Maximum number of failed deliveries to retry}';
    protected $description = 'Retries pending or failed webhook deliveries with exponential backoff.';

    public function handle(WebhookDispatcherService $dispatcher): int
    {
        $limit = (int)$this->option('limit');
        $deliveries = WebhookDelivery::with('webhook')
            ->where('status', WebhookDelivery::STATUS_FAILED)
            ->where('attempt', '<', 5)
            ->whereHas('webhook', fn($q) => $q->where('is_active', true))
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        $this->info("Found {$deliveries->count()} failed webhook deliveries to retry.");

        foreach ($deliveries as $delivery) {
            $delivery->increment('attempt');
            $dispatcher->sendDelivery($delivery->webhook, $delivery->event_name, $delivery->payload['data'] ?? []);
        }

        $this->info('Webhook retry processing completed.');
        return Command::SUCCESS;
    }
}
