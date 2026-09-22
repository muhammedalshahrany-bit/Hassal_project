<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class UserManagementController extends Controller
{
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
     * A screen that hardcoded this list would go stale on a rename; a screen
     * that reads `roles` offers things this endpoint must then refuse. Both
     * sides take the list from `config/rbac.php`, which the seeder builds.
     */
    public function roles(): JsonResponse
    {
        $platform = config('rbac.platform_roles', []);

        // The names arrive with what they actually grant. A list of bare labels is
        // how an admin ends up clicking the wrong one, and this screen's earlier
        // version offered `SellerAdmin` under a heading that said the platform —
        // the description is the part that lets the choice be checked.
        $roles = Role::query()
            ->with('permissions')
            ->whereIn('name', $platform)
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

    /** @return array<int, string> */
    private function platformRoles(): array
    {
        $platform = config('rbac.platform_roles', []);

        return is_array($platform) ? array_values($platform) : [];
    }

    /**
     * The lowercase vocabulary `users.status` holds, read from
     * `config/hassal.php` — the same list the column's CHECK constraint was
     * built from and the one login compares against.
     *
     * AuthController refuses login on any value other than 'active', so the
     * old uppercase validator was writing states that half-worked: 'SUSPENDED'
     * locked the account out while login described it as ACTIVE, and 'ACTIVE'
     * would have released a suspension without anyone deciding to.
     *
     * @return list<string>
     */
    private function accountStatuses(): array
    {
        /** @var list<string> $statuses */
        $statuses = array_values((array) config('hassal.accounts.statuses', ['active']));

        return $statuses;
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', $this->accountStatuses())],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($user->id === $request->user()->id && $validated['status'] !== 'active') {
            return ApiResponse::error(
                'لا يمكنك إيقاف حسابك الحالي',
                'CANNOT_DISABLE_SELF',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $previousStatus = $user->status;
        $user->update(['status' => $validated['status']]);

        // The status column alone changes nothing for a session already open.
        // `EnsureAccountIsActive` now refuses those per request, and deleting
        // the tokens here makes the refusal visible rather than waiting for a
        // client to notice it was rejected: the person is out of every device
        // the moment the decision is taken, which is what a suspension means.
        $closedSessions = 0;
        if ($validated['status'] !== 'active') {
            $closedSessions = $user->tokens()->count();
            $user->tokens()->delete();
        }

        // Audit Trail
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'USER_STATUS_UPDATE',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['status' => $previousStatus],
            'new_values' => ['status' => $validated['status'], 'reason' => $validated['reason']],
            'ip_address' => $request->ip(),
        ]);

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
     *
     * Three rules, all of them about the decision being attributable and
     * reversible: the role must be a platform role, a reason is required and
     * written to the audit row beside the grant, and the console cannot cut the
     * last person holding `SuperAdmin` out of the system — including its own
     * user, which is how an admin panel locks its own operator out.
     */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role_name' => ['required', 'string', Rule::in($this->platformRoles())],
            'reason'    => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $roleName = $validated['role_name'];

        if ($user->hasRole($roleName)) {
            return ApiResponse::error(
                'الحساب يحمل هذا الدور مسبقاً',
                'ROLE_ALREADY_ASSIGNED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['role' => $roleName]
            );
        }

        $user->assignRole($roleName);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'USER_ROLE_ASSIGNED',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => ['role' => $roleName, 'reason' => $validated['reason']],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(
            $user->load('roles:id,name'),
            "Role {$roleName} assigned to user",
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

        if (! in_array($roleName, $this->platformRoles(), true)) {
            return ApiResponse::error(
                'هذا الدور دور عمل داخل منشأة، ولا يُسحب من لوحة المنصة',
                'NOT_A_PLATFORM_ROLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['role' => $roleName, 'use' => 'PUT /organizations/{organizationId}/members/{memberId}']
            );
        }

        if (! $user->hasRole($roleName)) {
            return ApiResponse::error(
                'الحساب لا يحمل هذا الدور',
                'ROLE_NOT_HELD',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['role' => $roleName]
            );
        }

        if ($roleName === 'SuperAdmin') {
            if ($user->id === $request->user()->id) {
                return ApiResponse::error(
                    'لا يمكنك سحب دورك الإشرافي أثناء استخدامك للوحة',
                    'CANNOT_REVOKE_SELF',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if (User::role('SuperAdmin')->where('id', '!=', $user->id)->doesntExist()) {
                return ApiResponse::error(
                    'هذا هو آخر حساب بمشرف عام؛ سحبه يُغلق المنصة على نفسها',
                    'LAST_SUPER_ADMIN',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $user->removeRole($roleName);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'USER_ROLE_REVOKED',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['role' => $roleName],
            'new_values' => ['reason' => $validated['reason']],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(
            $user->load('roles:id,name'),
            "Role {$roleName} revoked from user"
        );
    }
}
