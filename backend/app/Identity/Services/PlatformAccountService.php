<?php

namespace App\Identity\Services;

use App\Core\Models\AuditLog;
use App\Core\Exceptions\ApiRefused;
use App\Auth\Services\PasswordCredentialService;
use App\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the platform console may do to an account, and what it may not.
 *
 * Three decisions live here, and all three exist because of something that
 * went wrong without them:
 *
 *   * A suspension means every open session stops. The status column alone
 *     would leave a signed-in handset working until its token expired, so the
 *     rows are deleted in the same act as the write — which is what makes the
 *     refusal visible rather than waiting for a client to notice it was
 *     rejected.
 *   * `users.status` holds lowercase values, read from `config/hassal.php` —
 *     the same list the column's CHECK constraint was built from and the one
 *     login compares against. The old uppercase validator wrote states that
 *     half-worked: 'SUSPENDED' locked the account out while login described it
 *     as ACTIVE.
 *   * Standing on the platform comes from `config('rbac.platform_roles')`.
 *     SellerAdmin / BuyerStaff and their kind describe what a person is inside
 *     one company, and that lives in `organization_memberships.role`; offering
 *     them here wrote a global row and left the person's actual role untouched,
 *     which is the bug an operator reported as "تغيير الدور لا ينعكس".
 *
 * The console cannot cut the last person holding `SuperAdmin` out of the system
 * — including its own user, which is how an admin panel locks its operator out.
 *
 * Every write takes a reason and lands in the audit log beside the change,
 * because a capability grant nobody can say who asked for it is a capability
 * grant nobody can take back.
 */
class PlatformAccountService
{
    /** The operator role, named in one place — see `User::PLATFORM_OPERATOR_ROLE`. */
    public const SUPER_ADMIN = User::PLATFORM_OPERATOR_ROLE;

    public function __construct(
        private readonly PasswordCredentialService $credentials,
    ) {
    }

    /**
     * The roles this console may assign: the platform ones, from the config.
     *
     * A screen that hardcoded this list would go stale on a rename, and a
     * screen that read the whole `roles` table would offer things this service
     * then has to refuse. Both sides take the list from here.
     *
     * @return list<string>
     */
    public function platformRoles(): array
    {
        $platform = config('rbac.platform_roles', []);

        return is_array($platform) ? array_values($platform) : [];
    }

    /**
     * The lowercase vocabulary `users.status` holds.
     *
     * @return list<string>
     */
    public function accountStatuses(): array
    {
        /** @var list<string> $statuses */
        $statuses = array_values((array) config('hassal.accounts.statuses', ['active']));

        return $statuses;
    }

    /**
     * Move an account to another state and end what its sessions could still do.
     *
     * @param  array{status: string, reason: string}  $validated
     * @return int How many tokens were closed by the change.
     */
    public function changeStatus(User $account, User $actor, array $validated, string $ip): int
    {
        if ($account->id === $actor->id && $validated['status'] !== 'active') {
            throw new ApiRefused(
                'لا يمكنك إيقاف حسابك الحالي',
                'CANNOT_DISABLE_SELF',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $previousStatus = $account->status;
        $account->update(['status' => $validated['status']]);

        $closedSessions = 0;

        if ($validated['status'] !== 'active') {
            $closedSessions = $account->tokens()->count();
            $this->credentials->revokeSessions($account);
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'USER_STATUS_UPDATE',
            'auditable_type' => User::class,
            'auditable_id' => $account->id,
            'old_values' => ['status' => $previousStatus],
            'new_values' => ['status' => $validated['status'], 'reason' => $validated['reason']],
            'ip_address' => $ip,
        ]);

        return $closedSessions;
    }

    /**
     * Give an account standing on the platform.
     *
     * A person cannot hold the same platform role twice: the row would say the
     * same thing twice, and a second audit line for it reads as though the
     * first one did not take.
     *
     * @param  array{role_name: string, reason: string}  $validated
     */
    public function assignRole(User $account, User $actor, array $validated, string $ip): void
    {
        $roleName = $validated['role_name'];

        if ($account->hasRole($roleName)) {
            throw new ApiRefused(
                'الحساب يحمل هذا الدور مسبقاً',
                'ROLE_ALREADY_ASSIGNED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['role' => $roleName]
            );
        }

        $account->assignRole($roleName);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'USER_ROLE_ASSIGNED',
            'auditable_type' => User::class,
            'auditable_id' => $account->id,
            'new_values' => ['role' => $roleName, 'reason' => $validated['reason']],
            'ip_address' => $ip,
        ]);
    }

    /**
     * Take platform standing away — without stranding the platform.
     */
    public function revokeRole(User $account, string $roleName, User $actor, string $reason, string $ip): void
    {
        if (!in_array($roleName, $this->platformRoles(), true)) {
            throw new ApiRefused(
                'هذا الدور دور عمل داخل منشأة، ولا يُسحب من لوحة المنصة',
                'NOT_A_PLATFORM_ROLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['role' => $roleName, 'use' => 'PUT /organizations/{organizationId}/members/{memberId}']
            );
        }

        if (!$account->hasRole($roleName)) {
            throw new ApiRefused(
                'الحساب لا يحمل هذا الدور',
                'ROLE_NOT_HELD',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['role' => $roleName]
            );
        }

        if ($roleName === self::SUPER_ADMIN) {
            if ($account->id === $actor->id) {
                throw new ApiRefused(
                    'لا يمكنك سحب دورك الإشرافي أثناء استخدامك للوحة',
                    'CANNOT_REVOKE_SELF',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if (User::role(self::SUPER_ADMIN)->where('id', '!=', $account->id)->doesntExist()) {
                throw new ApiRefused(
                    'هذا هو آخر حساب بمشرف عام؛ سحبه يُغلق المنصة على نفسها',
                    'LAST_SUPER_ADMIN',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $account->removeRole($roleName);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'USER_ROLE_REVOKED',
            'auditable_type' => User::class,
            'auditable_id' => $account->id,
            'old_values' => ['role' => $roleName],
            'new_values' => ['reason' => $reason],
            'ip_address' => $ip,
        ]);
    }
}
