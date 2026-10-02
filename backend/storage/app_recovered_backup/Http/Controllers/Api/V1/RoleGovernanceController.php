<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\RbacScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform door of the permissions system: who may hold what account-side.
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
 *     answers as nothing, so this door refusing it is the same rule stated twice
 *     rather than the only place it exists.
 *
 * `SuperAdmin` is editable by nobody but the config. It is declared `'*'`, the
 * seeder therefore gives it every row in the catalog, and a screen that replaced
 * its grants would silently turn the platform owner into a role with a checklist —
 * one that could lock itself out of the console that is fixing the checklist. Its
 * row is read here and written nowhere; changing it means editing the file, and the
 * file is under version control.
 *
 * Every write takes a reason and lands in the audit log. Unlike the company side,
 * there is no organization id to file it under: a platform act is attributed to the
 * account that performed it and to the whole market.
 */
class RoleGovernanceController extends Controller
{
    /**
     * The platform matrix: the catalog, its scopes, and the roles that hold it.
     */
    public function index(): JsonResponse
    {
        $roleNames = (array) config('rbac.platform_roles');

        $grants = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', $roleNames)
            ->select('roles.name as role', DB::raw('count(*) as holders'))
            ->groupBy('roles.name')
            ->pluck('holders', 'role');

        $roles = Role::whereIn('name', $roleNames)
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'name' => $role->name,
                // `all` rather than a count: the platform owner holds every name in
                // the catalog because the config declares `'*'`, and a screen that
                // showed a number there would be inviting somebody to edit it.
                'declared' => config('rbac.roles')[$role->name] ?? [],
                'is_immutable' => $role->name === 'SuperAdmin',
                'holder_count' => (int) ($grants[$role->name] ?? 0),
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::success([
            'permissions' => RbacScope::catalog(),
            'roles' => $roles,
            // The vocabulary the account door may never carry, published so the
            // console can label the difference instead of discovering it through a
            // refusal. It is the same list the write below applies.
            'account_forbidden' => RbacScope::namesFor(RbacScope::BUSINESS),
        ], 'Platform permissions retrieved');
    }

    /**
     * Replace what one platform role is worth.
     */
    public function updateRolePermissions(Request $request, string $roleName): JsonResponse
    {
        $validated = $this->validateGrant($request);

        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            return ApiResponse::error('الدور غير موجود', 'ROLE_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if ($roleName === 'SuperAdmin') {
            return ApiResponse::error(
                'دور مالك المنصة يُعرَّف في config/rbac.php ولا يُحرَّر من هنا',
                'ROLE_IMMUTABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!in_array($roleName, (array) config('rbac.platform_roles'), true)) {
            return ApiResponse::error(
                'هذا الدور ليس دور منصة: أدوار الشركات تُحرَّر من شاشة منشأتها',
                'ROLE_NOT_PLATFORM',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $refused = $this->refuse($validated['permissions']);

        if ($refused !== []) {
            return $this->refusal($refused);
        }

        $before = $role->permissions->pluck('name')->sort()->values()->all();

        $role->syncPermissions($validated['permissions']);

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit($request, 'PLATFORM_ROLE_PERMISSIONS_UPDATED', Role::class, $role->id, [
            'permissions' => $before,
        ], [
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'reason' => $validated['reason'],
        ]);

        return ApiResponse::success([
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ], 'Role permissions updated');
    }

    /**
     * One account's own grants, read the way the enforcement path reads them.
     */
    public function showUserPermissions(Request $request, int $id): JsonResponse
    {
        $user = User::with('permissions')->findOrFail($id);

        return ApiResponse::success([
            'user_id' => (int) $user->id,
            'roles' => $user->getRoleNames()->values()->all(),
            // Raw rows, unfiltered: this is the console that maintains them, and a
            // business name left behind by an old import is precisely what it needs
            // to see in order to clear it. `effective` below is the opposite view —
            // what the middleware actually answers.
            'grants' => $user->permissions->pluck('name')->sort()->values()->all(),
            'effective' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ], 'Account permissions retrieved');
    }

    /**
     * Give or take account-level capabilities, for one person or for many.
     *
     * `mode` is explicit rather than inferred: a console that maintains one
     * analyst's grants and a console that onboards a team of them are the same
     * request shape, and the difference between "these are all of them" and "add
     * these to what they have" is too large a difference to leave to a default
     * that a rebuilt payload could change.
     */
    public function updateUserPermissions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string'],
            'mode' => ['required', 'in:replace,add,remove'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $refused = $this->refuse($validated['permissions']);

        if ($refused !== []) {
            return $this->refusal($refused);
        }

        $ids = array_values(array_unique(array_map('intval', $validated['user_ids'])));

        /** @var list<User> $users */
        $users = User::with('permissions')->whereIn('id', $ids)->get()->all();

        $missing = array_values(array_diff($ids, array_map(static fn (User $user): int => (int) $user->id, $users)));

        if ($missing !== []) {
            return ApiResponse::error(
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

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return ApiResponse::success([
            'mode' => $validated['mode'],
            'applied' => $results,
        ], 'Account permissions updated');
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
    private function refusal(array $refused): JsonResponse
    {
        return ApiResponse::error(
            'لا تُسنَد للحساب من باب المنصة (صلاحيات المنشآت أو أسماء غير معلنة): ' . implode(', ', $refused),
            'ACCOUNT_PERMISSION_REFUSED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['permissions' => $refused]
        );
    }

    /**
     * @return array{permissions: list<string>, reason: string}
     */
    private function validateGrant(Request $request): array
    {
        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return [
            'permissions' => array_values(array_unique($validated['permissions'])),
            'reason' => (string) $validated['reason'],
        ];
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
