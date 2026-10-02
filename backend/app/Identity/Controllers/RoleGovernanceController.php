<?php

namespace App\Identity\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Core\Rbac\RbacScope;
use App\Core\Exceptions\ApiRefused;
use App\Identity\Models\User;
use App\Identity\Services\PlatformRoleGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The platform door of the permissions system: who may hold what account-side.
 *
 * Reading the matrix is this controller's job — the catalog, its scopes, the
 * roles that hold them, one account's raw rows. Writing it is capability law
 * and lives in App\Identity\Services\PlatformRoleGovernanceService: which names
 * a platform role may carry, which account may be touched at all, and what each
 * change leaves behind in the audit log.
 */
class RoleGovernanceController extends Controller
{
    public function __construct(
        private readonly PlatformRoleGovernanceService $governance,
    ) {
    }

    /**
     * The platform matrix: the catalog, its scopes, and the roles that hold it.
     */
    public function index(): JsonResponse
    {
        $roleNames = (array) config('rbac.platform_roles');

        $holders = DB::table('model_has_roles')
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
                'is_immutable' => $role->name === PlatformRoleGovernanceService::SUPER_ADMIN,
                'holder_count' => (int) ($holders[$role->name] ?? 0),
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

        try {
            $role = $this->governance->replaceRolePermissions($roleName, $validated, $request);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

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

        try {
            $result = $this->governance->applyPermissionsToAccounts($validated, $request);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($result, 'Account permissions updated');
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

}
