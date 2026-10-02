<?php

namespace App\Organizations\Support;

use App\Organizations\Models\OrganizationMemberPermission;
use App\Organizations\Models\OrganizationMembership;
use App\Core\Rbac\RbacScope;
use Illuminate\Support\Facades\DB;

/**
 * The shared vocabulary of the permission matrix: which names a company may
 * speak, which it is refused, and how a name becomes a row.
 *
 * Both halves of the matrix — a standing's grants and one person's overrides —
 * apply the same two refusals (a platform capability, and a name the catalog
 * never declared) and the same owner protection. Keeping the rule in one file
 * is what stops the two doors from drifting into disagreeing about whether the
 * same name is grantable.
 */
final class PermissionMatrixResolver
{
    /** The two capabilities an owner standing must never be without. */
    public const OWNER_CORE = ['members.manage', 'organizations.manage'];

    /**
     * The names the caller asked for, and the names this door refuses.
     *
     * Both come back as lists because the 422 has to name them — a matrix
     * editor cannot untick "the request was invalid", and the refusal is the
     * same rule the list of offered names applies, so the screen that shows
     * this response was shown a form that should not have contained the name.
     *
     * Which standing is being edited is not this method's business: the owner
     * protection belongs to the caller that knows it.
     *
     * @param  array<string, mixed>  $validated
     * @return array{names: list<string>, refused: list<string>}
     */
    public static function wanted(array $validated): array
    {
        return self::filter(
            array_values(array_unique((array) ($validated['permissions'] ?? [])))
        );
    }

    /**
     * @param  list<string>  $names
     * @return array{names: list<string>, refused: list<string>}
     */
    public static function filter(array $names): array
    {
        $refused = RbacScope::unacceptableInOrganization($names);

        $unknown = array_values(array_filter(
            $names,
            static fn (string $name): bool => !OrganizationMembership::isCatalogued($name)
        ));

        return [
            'names' => array_values(array_diff($names, [...$refused, ...$unknown])),
            'refused' => array_values(array_unique([...$refused, ...$unknown])),
        ];
    }

    /**
     * The names a company may put in front of its people, with their scope.
     *
     * @return list<array{name: string, scope: string}>
     */
    public static function grantablePermissions(): array
    {
        return array_values(array_filter(
            RbacScope::catalog(),
            static fn (array $entry): bool => RbacScope::exercisableInsideOrganization($entry['name'])
        ));
    }

    /**
     * @param  array<array-key, string>  $names
     * @return array<string, int>  name => permissions.id
     */
    public static function permissionIds(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return DB::table('permissions')->whereIn('name', $names)->pluck('id', 'name')->all();
    }

    /**
     * The names this person's override rows hold for one effect.
     *
     * @return list<string>
     */
    public static function overrideNames(OrganizationMembership $membership, string $effect): array
    {
        $membership->loadMissing('overrides.permission');

        return $membership->overrides
            ->where('effect', $effect)
            ->map(fn (OrganizationMemberPermission $override): ?string => $override->permission?->name)
            ->filter()
            ->values()
            ->all();
    }
}
