<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMembership extends Model
{
    use HasFactory;

    /** The standing that cannot simply be handed out, and must never run out. */
    public const ROLE_OWNER = 'owner';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'is_active',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The only roles a membership may hold, as `role => label`.
     *
     * Every reader of this goes through here rather than writing the list out:
     * the validators in `AddMemberRequest` and `updateMember`, the options the
     * member screens offer, and the CHECK constraint the column carries. The
     * three once disagreed, and the disagreement was invisible until somebody
     * tried to save a row whose stored value a validator had never accepted.
     */
    public static function roles(): array
    {
        /** @var array<string, string> $roles */
        $roles = (array) config('rbac.membership_roles');

        return $roles === [] ? [self::ROLE_OWNER => 'مالك المنشأة'] : $roles;
    }

    /** @return list<string> */
    public static function roleNames(): array
    {
        return array_keys(self::roles());
    }

    public static function isRole(mixed $role): bool
    {
        return is_string($role) && isset(self::roles()[$role]);
    }

    /**
     * The Laravel `in:` validation string for the vocabulary.
     */
    public static function roleRule(): string
    {
        return 'in:' . implode(',', self::roleNames());
    }

    /**
     * What a standing inside a company is allowed to do.
     *
     * The list lives in `config/rbac.php`. Returning names rather than Permission
     * models keeps this readable without a query: a caller either asks "does this
     * standing cover X" (a string comparison) or hands the names to
     * `User::getAllPermissions()`, which needs models and loads them once.
     *
     * @return list<string>
     */
    public static function permissionsFor(?string $role): array
    {
        if ($role === null) {
            return [];
        }

        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('rbac.membership_permissions');

        return array_values($matrix[$role] ?? []);
    }

    /**
     * Every permission any company standing can reach.
     *
     * This is the boundary of business authority: a name outside it can only come
     * from a platform role, so no combination of company standings produces it.
     * `User::hasPermissionTo()` uses it to decide when to consult the membership,
     * and a test asserts the other direction — that none of the platform's own
     * powers (moderating a listing, verifying a business, ruling a dispute,
     * editing the platform's settings) ever appear on the company side.
     *
     * @return list<string>
     */
    public static function businessPermissions(): array
    {
        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('rbac.membership_permissions');

        if ($matrix === []) {
            return [];
        }

        return array_values(array_unique(array_merge(...array_values($matrix))));
    }

    public static function isBusinessPermission(string $permission): bool
    {
        return in_array($permission, self::businessPermissions(), true);
    }

    /**
     * Who may change the staffing of a company.
     *
     * Asked of the matrix rather than answered here: `members.manage` belongs to
     * `owner` and `admin` in `config/rbac.php`, so which standings may hire,
     * re-rank and remove is maintained in one list and this method only reads it.
     * A platform SuperAdmin is allowed everywhere by definition, so callers that
     * already know that pass the flag in rather than repeating the check here.
     */
    public static function canManage(?string $membershipRole, bool $isPlatformAdmin = false): bool
    {
        if ($isPlatformAdmin) {
            return true;
        }

        return in_array('members.manage', self::permissionsFor($membershipRole), true);
    }
}
