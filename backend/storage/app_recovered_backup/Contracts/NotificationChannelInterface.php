<?php

namespace App\Contracts;

use App\Models\User;

interface NotificationChannelInterface
{
    /**
     * Channel identifier (database, mail, sms, fcm).
     */
    public function getIdentifier(): string;

    /**
     * Sends the notification to recipient.
     */
    public function send(User $recipient, string $title, string $body, array $data = []): bool;
}
