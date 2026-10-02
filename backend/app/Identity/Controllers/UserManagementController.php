<?php

namespace App\Identity\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\Controller;
use App\Core\Exceptions\ApiRefused;
use App\Identity\Models\User;
use App\Identity\Services\PlatformAccountService;
use App\Organizations\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform console's account desk: read an account, change its state, change
 * its platform standing.
 *
 * Reading is shaped here. Deciding — whether a suspension is allowed, whether a
 * role is a platform role, whether taking it would strand the platform — is
 * account law and lives in App\Identity\Services\PlatformAccountService.
 */
class UserManagementController extends Controller
{
    public function __construct(
        private readonly PlatformAccountService $accounts,
    ) {
    }

    /**
     * The platform-admin listing.
     *
     * The rows are shaped, not dumped. `User::with('organizations')` serializes
     * the pivot as `organizations[].pivot.role`, while every other endpoint in
     * this API — `/auth/me` included — states the standing as
     * `organizations[].role`. The console read the documented field, found
     * nothing, and fell back to its first option: each account then appeared to
     * be «مالك المنشأة» whatever it really was, which is the second half of the
     * complaint that role changes "do not reflect". A listing that hands back raw
     * models also carries guard names, pivot timestamps and legal numbers it has
     * no use for, so the fields the screen reads are named here once.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['roles', 'organizations']);

        if ($request->filled('search')) {
            $term = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term)
                  ->orWhere('phone', 'like', $term);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $users = $query->orderBy('id', 'desc')->paginate($request->query('per_page', 20));

        return ApiResponse::paginated(
            $users->through(fn (User $user) => $this->accountRow($user)),
            'Users retrieved successfully'
        );
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with(['roles', 'organizations'])->findOrFail($id);

        return ApiResponse::success($this->accountRow($user), 'User details retrieved');
    }

    /**
     * One account as this console needs it: who the person is to the platform,
     * and what they are inside each business they belong to.
     *
     * `roles` holds every Spatie row attached to the account, including the
     * legacy business names (`SellerAdmin`, `BuyerStaff`). The console tells
     * those apart from platform roles by comparing against `roles()` — the same
     * list this controller will accept an assignment from — so the row itself
     * does not pretend to know which layer a name belongs to.
     *
     * Inactive memberships stay visible on purpose. A platform console that
     * silently dropped them would hide the very fact an operator is asking
     * about when they wonder why someone "has no role" in a company.
     *
     * @return array<string, mixed>
     */
    private function accountRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'national_id' => $user->national_id,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at,
            'phone_verified_at' => $user->phone_verified_at,
            'created_at' => $user->created_at,
            'roles' => $user->roles->pluck('name')->values()->all(),
            'organizations' => $user->organizations->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name_ar' => $organization->name_ar,
                'name_en' => $organization->name_en,
                'type' => $organization->type,
                'status' => $organization->status,
                'role' => $organization->pivot->role,
                'membership_active' => (bool) $organization->pivot->is_active,
                'joined_at' => $organization->pivot->joined_at,
            ])->values()->all(),
        ];
    }

    /**
     * The roles this console may assign: the platform ones, from the config.
     *
     * The `roles` table also holds SellerAdmin / SellerStaff / BuyerAdmin /
     * BuyerStaff. Those describe what a person is inside a company, and that
     * standing lives in `organization_memberships.role` — one row per business.
     * Offering them here was the bug an operator reported: "تغيير الدور" wrote a
     * global row and left the person's actual role in their own organization
     * untouched, so the control did not match its label.
     *
     * The names arrive with what they actually grant. A list of bare labels is
     * how an admin ends up clicking the wrong one, and this screen's earlier
     * version offered `SellerAdmin` under a heading that said the platform —
     * the description is the part that lets the choice be checked.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->whereIn('name', $this->accounts->platformRoles())
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permission_count' => $role->permissions->count(),
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ])->all(),
            'Roles retrieved successfully'
        );
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', $this->accounts->accountStatuses())],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $closedSessions = $this->accounts->changeStatus($user, $request->user(), $validated, $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $user->only(['id', 'name', 'email', 'status']),
            "User status updated to {$validated['status']}",
            Response::HTTP_OK,
            [],
            ['closed_sessions' => $closedSessions]
        );
    }

    /**
     * Give an account standing on the platform.
     */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role_name' => ['required', 'string', Rule::in($this->accounts->platformRoles())],
            'reason'    => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $this->accounts->assignRole($user, $request->user(), $validated, $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $user->load('roles:id,name'),
            "Role {$validated['role_name']} assigned to user",
            Response::HTTP_OK,
            [],
            ['reason' => $validated['reason']]
        );
    }

    /**
     * Take platform standing away — without stranding the platform.
     */
    public function revokeRole(Request $request, int $id, string $roleName): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $this->accounts->revokeRole($user, $roleName, $request->user(), $validated['reason'], $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $user->load('roles:id,name'),
            "Role {$roleName} revoked from user"
        );
    }

}
