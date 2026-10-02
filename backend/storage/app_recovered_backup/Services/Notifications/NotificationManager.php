<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelInterface;
use App\Models\User;
use App\Models\Organization;
use App\Support\PreferenceCatalog;

/**
 * The one door a notice goes out of.
 *
 * Two things are decided here rather than at each call site, and both are
 * things a caller should not be trusted with.
 *
 * The first is *whether the account wants to hear at all*. A person who turned
 * notifications off in the app — on any handset, on the web — has said so on
 * their account record, and a producer that kept writing rows would make the
 * switch a lie. The check is here because a check at every call site is a check
 * at every future call site, and the next producer written will not remember to
 * add it.
 *
 * The second is *which channel*. Only `database` is wired to anything a user can
 * read today; `email` exists as an adapter and is deliberately never selected by
 * default, because sending mail from a platform whose SMTP has not been
 * configured is a silent failure that looks like a delivered notice.
 */
class NotificationManager
{
    /**
     * Which preference governs which notice type.
     *
     * The type travels in the payload's `type` key — the same string the app
     * routes on to open the right screen — so a notice cannot be gated by one
     * label and displayed under another.
     */
    private const TYPE_PREFERENCES = [
        'DEAL' => PreferenceCatalog::NOTIFY_DEALS,
        'PURCHASE_REQUEST' => PreferenceCatalog::NOTIFY_DEALS,
        'RESERVATION' => PreferenceCatalog::NOTIFY_DEALS,
        // Market news, not a decision about the person's own listing: the two
        // reach different people and are switched off by different switches.
        'OFFER_PUBLISHED' => PreferenceCatalog::NOTIFY_OFFERS,
        'OFFER' => PreferenceCatalog::NOTIFY_MODERATION,
        'DOCUMENT' => PreferenceCatalog::NOTIFY_MODERATION,
        'ORGANIZATION' => PreferenceCatalog::NOTIFY_MODERATION,
        'COMMENT' => PreferenceCatalog::NOTIFY_MODERATION,
        'DEMAND' => PreferenceCatalog::NOTIFY_DEMANDS,
        'QUOTATION' => PreferenceCatalog::NOTIFY_DEMANDS,
        'MATCH' => PreferenceCatalog::NOTIFY_DEMANDS,
        // An answer to a report about the platform itself. Mapped rather than
        // left unknown: an unmapped type falls to the master switch alone, and a
        // person who muted their deal traffic would then keep getting support
        // mail they had a switch for but never saw honoured.
        'SUPPORT' => PreferenceCatalog::NOTIFY_SUPPORT,
    ];

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
        if (! $this->wants($recipient, $data)) {
            return;
        }

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

    /**
     * Every account that holds an active seat in this business.
     *
     * A moderation decision is about the company, and the company is a set of
     * people — one of whom may be on leave. Notifying only `uploaded_by` means
     * a returned document can sit unseen for a week, so the decision goes to the
     * membership and the sender's own copy is de-duplicated by the caller rather
     * than by a rule that quietly drops the second write.
     */
    public function notifyOrganizationMembers(
        Organization $organization,
        string $title,
        string $body,
        array $data = [],
        array $channelKeys = ['database'],
        ?int $exceptUserId = null
    ): void {
        $organization->memberships()
            ->where('is_active', true)
            ->with('user')
            ->get()
            ->each(function ($membership) use ($title, $body, $data, $channelKeys, $exceptUserId): void {
                $user = $membership->user;

                if ($user === null || $user->id === $exceptUserId) {
                    return;
                }

                $this->notify($user, $title, $body, $data, $channelKeys);
            });
    }

    /**
     * Every account on the platform, minus the one that caused it.
     *
     * This is the market speaking rather than a business decision reaching its
     * owner, and it goes through the same door on purpose: the switch that
     * mutes it is an account preference, and a broadcast that wrote rows before
     * reading them would be a person's «أطفئ التنبيهات» answered with a shade
     * full of things they asked not to be told.
     *
     * Chunked by id, because the alternative is holding every account in memory
     * to write one notice, and the accounts keep growing while the offer does not.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $exceptUserIds  accounts the news is about rather
     *                                          than news for — the seller of a
     *                                          listing they just published
     */
    public function notifyEveryone(
        string $title,
        string $body,
        array $data = [],
        array $exceptUserIds = [],
        int $chunk = 200
    ): void {
        User::query()
            ->when($exceptUserIds !== [], fn ($query) => $query->whereNotIn('id', $exceptUserIds))
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use ($title, $body, $data): void {
                foreach ($users as $user) {
                    $this->notify($user, $title, $body, $data);
                }
            });
    }

    /**
     * Has this person asked not to receive this kind of notice?
     *
     * Absent preferences read as *yes, send it*: the catalog's defaults are all
     * on, and a person who has never seen the settings screen must not be
     * silently unsubscribed from the notices that tell them their paperwork was
     * rejected.
     */
    private function wants(User $recipient, array $data): bool
    {
        $preferences = $recipient->preferences();

        if (($preferences[PreferenceCatalog::NOTIFICATIONS_ENABLED] ?? true) !== true) {
            return false;
        }

        $type = is_string($data['type'] ?? null) ? strtoupper($data['type']) : '';
        $preference = self::TYPE_PREFERENCES[$type] ?? null;

        // An unknown type is not a licence to skip the check — it is a producer
        // that has not been mapped yet, and the safe reading of "we do not know
        // which switch this falls under" is the master switch alone, which was
        // tested just above.
        if ($preference === null) {
            return true;
        }

        return ($preferences[$preference] ?? true) === true;
    }
}
