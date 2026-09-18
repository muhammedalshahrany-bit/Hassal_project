<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Models\User;
use App\Models\Organization;

class NotificationManager
{
    protected array $channels = [];

    public function __construct()
    {
        $this->registerChannel(new DatabaseNotificationChannel());
        $this->registerChannel(new EmailNotificationChannel());
    }

    public function registerChannel(NotificationChannelInterface $channel): void
    {
        $this->channels[$channel->getIdentifier()] = $channel;
    }

    /**
     * Broadcasts notification to all registered active channels.
     */
    public function notify(User $recipient, string $title, string $body, array $data = [], array $channelKeys = ['database']): void
    {
        foreach ($channelKeys as $key) {
            if (isset($this->channels[$key])) {
                $this->channels[$key]->send($recipient, $title, $body, $data);
            }
        }
    }

    public function notifyOrganization(Organization $organization, string $title, string $body, array $data = [], array $channelKeys = ['database']): void
    {
        $organization->memberships()
            ->where('is_active', true)
            ->with('user')
            ->get()
            ->each(function ($membership) use ($title, $body, $data, $channelKeys): void {
                if ($membership->user) {
                    $this->notify($membership->user, $title, $body, $data, $channelKeys);
                }
            });
    }
}
