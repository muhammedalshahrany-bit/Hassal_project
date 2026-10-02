<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use App\Notifications\Models\Notification;
use App\Identity\Models\User;
use App\Core\Tenant\TenantContext;

class DatabaseNotificationChannel implements NotificationChannelInterface
{
    public function getIdentifier(): string
    {
        return 'database';
    }

    public function send(User $recipient, string $title, string $body, array $data = []): bool
    {
        Notification::create([
            'user_id' => $recipient->id,
            'title_ar' => $title,
            'title_en' => $title,
            'body_ar' => $body,
            'body_en' => $body,
            'type' => $data['type'] ?? 'GENERAL',
            'data' => $data,
            'organization_id' => $data['organization_id'] ?? TenantContext::id(),
            'is_read' => false,
        ]);

        return true;
    }
}
