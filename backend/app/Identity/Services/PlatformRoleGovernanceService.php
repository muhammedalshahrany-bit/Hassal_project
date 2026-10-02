<?php

namespace App\Identity\Services;

use App\Core\Models\AuditLog;
use App\Core\Rbac\RbacScope;
use App\Core\Exceptions\ApiRefused;
use App\Identity\Models\User;
use App\Organizations\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write side of the platform's permissions: role grants and account grants.
 *
 * Two subjects live on this side of the boundary and nothing else does.
 *
 *   * A platform *role* — one of `config('rbac.php')['platform_roles']` — is a
 *     standing a person holds as themselves, in front of the whole market. What it
 *     may carry is whatever the catalog classifies `platform` or `both`; a
 *     `business` name is refused here by the same rule that refuses it inside a
 *     company, from the other direction. Stamping `offers.create` onto a platform
 *     role would make it follow the account into businesses it has no relation to,
 *     which is exactly the fault the retired SellerAdmin roles were emptied for.
 *   * An individual *account grant* is a capability given to one person outside any
 *     company: the analyst who is not staff anywhere but must read every book, the
 *     contractor whose desk lasts a week. These are direct `model_has_permissions`
 *     rows, and `User::hasPermissionTo()` reads them for `platform` and `both`
 *     names only — a business name written here by any route, script or console
 *     answers as nothing, so this service refusing it is the same rule stated
 *     twice rather than the only place it exists.
 *
 * `SuperAdmin` is editable by nobody but the config. It is declared `'*'`, the
 * seeder therefore gives it every row in the catalog, and a screen that replaced
 * its grants would silently turn the platform owner into a role with a checklist —
 * one that could lock itself out of the console that is fixing the checklist. Its
 * row is read here and written nowhere; changing it means editing the file, and
 * the file is under version control.
 *
 * Every write takes a reason and lands in the audit log. Unlike the company side,
 * there is no organization id to file it under: a platform act is attributed to
 * the account that performed it and to the whole market.
 */
class PlatformRoleGovernanceService
{
    /** The operator role, named in one place — see `User::PLATFORM_OPERATOR_ROLE`. */
    public const SUPER_ADMIN = User::PLATFORM_OPERATOR_ROLE;

    /**
     * Replace what one platform role is worth.
     *
     * The role is looked up before its name is checked against the platform list
     * because those two refusals mean different things to the operator: "no such
     * role" and "that role is not edited from this screen" send them to different
     * places.
     *
     * @param  array{permissions: list<string>, reason: string}  $validated
     */
    public function replaceRolePermissions(string $roleName, array $validated, Request $request): Role
    {
        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            throw new ApiRefused('الدور غير موجود', 'ROLE_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if ($roleName === self::SUPER_ADMIN) {
            throw new ApiRefused(
                'دور مالك المنصة يُعرَّف في config/rbac.php ولا يُحرَّر من هنا',
                'ROLE_IMMUTABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!in_array($roleName, (array) config('rbac.platform_roles'), true)) {
            throw new ApiRefused(
                'هذا الدور ليس دور منصة: أدوار الشركات تُحرَّر من شاشة منشأتها',
                'ROLE_NOT_PLATFORM',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $refused = $this->refuse($validated['permissions']);

        if ($refused !== []) {
            throw $this->refusal($refused);
        }

        $before = $role->permissions->pluck('name')->sort()->values()->all();

        $role->syncPermissions($validated['permissions']);

        $this->forgetPermissionCache();

        $this->audit($request, 'PLATFORM_ROLE_PERMISSIONS_UPDATED', Role::class, $role->id, [
            'permissions' => $before,
        ], [
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'reason' => $validated['reason'],
        ]);

        return $role;
    }

    /**
     * Give or take account-level capabilities, for one person or for many.
     *
     * `mode` is explicit rather than inferred: a console that maintains one
     * analyst's grants and a console that onboards a team of them are the same
     * request shape, and the difference between "these are all of them" and "add
     * these to what they have" is too large a difference to leave to a default
     * that a rebuilt payload could change.
     *
     * The whole batch is checked before the first row is written: an unknown
     * account in a list of five means none of the five move, rather than four
     * carrying a grant a screen then reports as an all-or-nothing action.
     *
     * @param  array{user_ids: list<int>, permissions: list<string>, mode: string, reason: string}  $validated
     * @return array{mode: string, applied: list<array{user_id: int, permissions: list<string>, changed: bool}>}
     */
    public function applyPermissionsToAccounts(array $validated, Request $request): array
    {
        $refused = $this->refuse($validated['permissions']);

        if ($refused !== []) {
            throw $this->refusal($refused);
        }

        $ids = array_values(array_unique(array_map('intval', $validated['user_ids'])));

        /** @var list<User> $users */
        $users = User::with('permissions')->whereIn('id', $ids)->get()->all();

        $missing = array_values(array_diff($ids, array_map(static fn (User $user): int => (int) $user->id, $users)));

        if ($missing !== []) {
            throw new ApiRefused(
                'حسابات غير موجودة: ' . implode(', ', $missing),
                'USER_NOT_FOUND',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['user_ids' => $missing]
            );
        }

        $results = [];

        foreach ($users as $user) {
            $current = $user->permissions->pluck('name')->all();

            $next = match ($validated['mode']) {
                'replace' => $validated['permissions'],
                'add' => array_values(array_unique([...$current, ...$validated['permissions']])),
                'remove' => array_values(array_diff($current, $validated['permissions'])),
            };

            $user->syncPermissions($next);

            $this->audit($request, 'PLATFORM_ACCOUNT_PERMISSIONS_UPDATED', User::class, $user->id, [
                'permissions' => $current,
            ], [
                'permissions' => $next,
                'mode' => $validated['mode'],
                'reason' => $validated['reason'],
            ]);

            $results[] = [
                'user_id' => (int) $user->id,
                'permissions' => $next,
                'changed' => $current !== $next,
            ];
        }

        $this->forgetPermissionCache();

        return ['mode' => $validated['mode'], 'applied' => $results];
    }

    /**
     * Names this door may not attach to an account, with the reason each carries.
     *
     * @param  array<array-key, string>  $names
     * @return list<string>
     */
    private function refuse(array $names): array
    {
        $business = RbacScope::unacceptableOnAccount($names);

        $unknown = array_values(array_filter(
            $names,
            static fn (string $name): bool => !OrganizationMembership::isCatalogued($name)
        ));

        return array_values(array_unique([...$business, ...$unknown]));
    }

    /**
     * @param  list<string>  $refused
     */
    private function refusal(array $refused): ApiRefused
    {
        return new ApiRefused(
            'لا تُسنَد للحساب من باب المنصة (صلاحيات المنشآت أو أسماء غير معلنة): ' . implode(', ', $refused),
            'ACCOUNT_PERMISSION_REFUSED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['permissions' => $refused]
        );
    }

    private function forgetPermissionCache(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(Request $request, string $action, string $type, int|string $id, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'organization_id' => null,
            'ip_address' => $request->ip(),
        ]);
    }
}
