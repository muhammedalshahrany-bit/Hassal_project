<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;

/**
 * One capability, added to or taken away from one person inside one company.
 *
 * A row here is not a permission: it is an instruction about one, read next to
 * whatever the member's standing is worth. `effect` says which — `grant` gives the
 * person something their standing does not carry, `deny` takes back something it
 * does.
 *
 * Both directions exist because a company that can only add cannot answer the
 * ordinary request «everybody keeps what they have, except this one stops deleting
 * listings». An additive-only table answers that by demoting the person, which
 * changes a great deal more than was asked, and the demotion outlives the reason
 * for it.
 *
 * Denial therefore wins, always: a row that refuses is more specific than a role
 * that allows, and the more specific statement is the one that was written about
 * this particular person. The same is true of the boundary the row cannot cross —
 * a `platform` name attached here is refused at the door and ignored on the read,
 * so a bad row cannot reach a route even through a database console.
 *
 * Rows belong to the membership, not to the account, and that is the whole point:
 * the same person at a second company inherits nothing from this one, and leaving
 * the company deletes the row by cascade.
 */
class OrganizationMemberPermission extends Model
{
    use HasFactory;

    public const EFFECT_GRANT = 'grant';
    public const EFFECT_DENY = 'deny';

    /** Named for the people table it hangs off, so the string has to be written. */
    protected $table = 'organization_membership_permissions';

    protected $fillable = [
        'organization_membership_id',
        'permission_id',
        'effect',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'organization_membership_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * @return list<string> the effects the `effect` column accepts
     */
    public static function effects(): array
    {
        return [self::EFFECT_GRANT, self::EFFECT_DENY];
    }

    public static function isEffect(mixed $effect): bool
    {
        return is_string($effect) && in_array($effect, self::effects(), true);
    }
}
