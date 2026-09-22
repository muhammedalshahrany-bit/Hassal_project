<?php

namespace App\Support;

use App\Models\OrganizationDocument;
use App\Models\ProductImage;
use App\Services\SystemSettingsService;

/**
 * The one ceiling on how much disk a single business may occupy.
 *
 * It is a setting (`uploads.quota_bytes_per_organization`), not a constant,
 * because how much storage a tenant gets is a commercial decision an operator
 * makes — the same reason the per-file ceilings deliberately live in
 * `config/hassal.php` and NOT here: those are limits of the machine, and a web
 * form must not be able to raise them past what the server can hold.
 *
 * The quota spans both kinds of bytes one business owns: product photographs
 * (on the public disk) and filed legal papers (on the private disk). Counting
 * only one would let a tenant fill the other disk unbounded while its "quota"
 * still read as free. A value of zero means no ceiling — the operator chose to
 * leave storage unmetered, and that choice is honoured rather than overridden
 * with a guessed default.
 */
final class UploadQuota
{
    /**
     * Whether accepting `$incomingBytes` for `$organizationId` stays within the
     * tenant's quota. Returns the ceiling and current usage either way so a
     * refusal can tell the caller how close it is rather than only "no".
     *
     * @return array{allowed: bool, quota_bytes: int, used_bytes: int, incoming_bytes: int}
     */
    public static function check(int $organizationId, int $incomingBytes): array
    {
        $quota = (int) app(SystemSettingsService::class)->get('uploads.quota_bytes_per_organization', 0);

        $used = self::usedBytes($organizationId);

        return [
            'allowed'        => $quota <= 0 || ($used + $incomingBytes) <= $quota,
            'quota_bytes'    => $quota,
            'used_bytes'     => $used,
            'incoming_bytes' => $incomingBytes,
        ];
    }

    /** Total bytes this business already holds across images and documents. */
    public static function usedBytes(int $organizationId): int
    {
        $images = (int) ProductImage::query()->where('organization_id', $organizationId)->sum('size_bytes');
        $documents = (int) OrganizationDocument::query()->where('organization_id', $organizationId)->sum('size_bytes');

        return $images + $documents;
    }
}
