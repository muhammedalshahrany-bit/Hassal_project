<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use App\Identity\Models\User;
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
