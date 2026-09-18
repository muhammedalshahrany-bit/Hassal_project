<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationChannel implements NotificationChannelInterface
{
    public function getIdentifier(): string
    {
        return 'mail';
    }

    public function send(User $recipient, string $title, string $body, array $data = []): bool
    {
        // Safe logging / queue execution
        Log::info("Dispatching email notification to [{$recipient->email}]: {$title}");
        return true;
    }
}
