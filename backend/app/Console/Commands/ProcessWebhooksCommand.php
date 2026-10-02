<?php

namespace App\Console\Commands;

use App\Platform\Models\WebhookDelivery;
use App\Platform\Services\WebhookDispatcherService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessWebhooksCommand extends Command
{
    protected $signature = 'app:process-webhooks {--limit=50 : Maximum number of failed deliveries to retry}';
    protected $description = 'Retries failed webhook deliveries — one attempt per scheduler tick, up to the configured attempt cap (no backoff delay).';

    public function handle(WebhookDispatcherService $dispatcher): int
    {
        $maxAttempts = (int) config('hassal.webhooks.max_attempts', 5);
        $limit = (int)$this->option('limit');
        $deliveries = WebhookDelivery::with('webhook')
            ->where('status', WebhookDelivery::STATUS_FAILED)
            ->where('attempt', '<', $maxAttempts)
            ->whereHas('webhook', fn($q) => $q->where('is_active', true))
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        $this->info("Found {$deliveries->count()} failed webhook deliveries to retry.");

        foreach ($deliveries as $delivery) {
            // retryDelivery() counts the attempt and updates this same row.
            // Sending through sendDelivery() used to create a new row each
            // time, so one failing endpoint kept generating retryable work.
            $dispatcher->retryDelivery($delivery);
        }

        $this->info('Webhook retry processing completed.');
        return Command::SUCCESS;
    }
}
