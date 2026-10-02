<?php

namespace App\Organizations\Services;

use App\Core\Models\AuditLog;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staffing a company: who may add, re-role, or remove a member, and what the
 * company's own survival rules refuse along the way.
 *
 * Two kinds of rule meet here. Permission — whoever this company gave
 * `members.manage` staffs it, and ownership outranks every admin in the
 * company, including the caller's. And continuity — a company with no active
 * owner has no one who can appoint one, so the edit that would empty it is
 * refused instead of logged.
 */
class OrganizationStaffingService
{
    /**
     * Add — or re-state — a person on the company's roster.
     *
     * @param array<string, mixed> $validated
     *
     * @return array{membership: OrganizationMembership, existed: bool}
     *
     * @throws ApiRefused
     */
    public function addMember(Organization $org, ?User $actor, array $validated, string $ip): array
    {
        $this->assertMayStaff($actor, $org, 'لا تملك صلاحية إضافة أعضاء إلى هذه المنشأة: الإضافة لمن يدير أعضاءها');

        $targetUserId = $validated['user_id'] ?? null;

        if (!$targetUserId && !empty($validated['email'])) {
            $user = User::where('email', $validated['email'])->first();
            if (!$user) {
                throw new ApiRefused(
                    'لا يوجد حساب مسجّل بهذا البريد على المنصة',
                    'USER_NOT_FOUND',
                    Response::HTTP_NOT_FOUND
                );
            }
            $targetUserId = $user->id;
        }

        $existing = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $targetUserId)
            ->first();

        // Ownership outranks every admin in the company, including the caller's,
        // so moving it is not something an admin may do to somebody — or to
        // themselves by adding a co-owner and resigning. Only the current owner
        // or the platform may set or clear it.
        if ($this->touchesOwnership($validated['role'], $existing?->role)
            && !$this->mayMoveOwnership($actor, $org)) {
            throw new ApiRefused(
                'نقل الملكية أو منحها يكون من المالك الحالي أو من إدارة المنصة',
                'OWNERSHIP_MOVE_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $membership = OrganizationMembership::updateOrCreate(
            [
                'organization_id' => $org->id,
                'user_id' => $targetUserId,
            ],
            [
                'role' => $validated['role'],
                'is_active' => true,
                'joined_at' => now(),
            ]
        );

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $existing ? 'ORG_MEMBER_UPDATED' : 'ORG_MEMBER_ADDED',
            'auditable_type' => OrganizationMembership::class,
            'auditable_id' => $membership->id,
            'old_values' => $existing ? ['role' => $existing->role, 'is_active' => $existing->is_active] : null,
            'new_values' => ['role' => $membership->role, 'reason' => $validated['reason']],
            'organization_id' => $org->id,
            'ip_address' => $ip,
        ]);

        return ['membership' => $membership, 'existed' => $existing !== null];
    }

    /**
     * Change a member's standing (and, if asked, their seat at the desk).
     *
     * @param array<string, mixed> $validated
     *
     * @throws ApiRefused
     */
    public function updateMember(Organization $org, int|string $userId, User $actor, array $validated, string $ip): OrganizationMembership
    {
        $this->assertMayStaff($actor, $org, 'لا تملك صلاحية تغيير أدوار الأعضاء في هذه المنشأة');

        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $previous = ['role' => $membership->role, 'is_active' => $membership->is_active];

        // Permission before business rules: telling an admin that the last owner
        // cannot be demoted would itself be a way of probing the roster.
        if ($this->touchesOwnership($validated['role'], $membership->role)
            && !$this->mayMoveOwnership($actor, $org)) {
            throw new ApiRefused(
                'نقل الملكية أو منحها يكون من المالك الحالي أو من إدارة المنصة',
                'OWNERSHIP_MOVE_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        // A company with no active owner has no one who can appoint one, so the
        // demotion that would empty it is refused instead of logged.
        if ($this->wouldLeaveWithoutOwner($membership, $validated)) {
            throw new ApiRefused(
                'لا يمكن إزالة آخر مالك نشط للمنشأة؛ عيّن مالكًا آخر أولًا',
                'LAST_OWNER_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $membership->update([
            'role' => $validated['role'],
            ...array_key_exists('is_active', $validated) ? ['is_active' => $validated['is_active']] : [],
        ]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'ORG_MEMBER_ROLE_CHANGED',
            'auditable_type' => OrganizationMembership::class,
            'auditable_id' => $membership->id,
            'old_values' => $previous,
            'new_values' => ['role' => $membership->role, 'is_active' => $membership->is_active, 'reason' => $validated['reason']],
            'organization_id' => $org->id,
            'ip_address' => $ip,
        ]);

        return $membership;
    }

    /**
     * Take a person off the active roster.
     *
     * @throws ApiRefused
     */
    public function removeMember(Organization $org, int|string $userId, User $actor, ?string $reason, string $ip): OrganizationMembership
    {
        $this->assertMayStaff($actor, $org, 'لا تملك صلاحية إزالة أعضاء من هذه المنشأة');

        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Taking an owner out of the company is an ownership change as far as
        // that person is concerned, so it obeys the same rule as demoting one.
        if ($membership->role === OrganizationMembership::ROLE_OWNER
            && !$this->mayMoveOwnership($actor, $org)) {
            throw new ApiRefused(
                'إزالة مالك تكون بقرار منه أو من إدارة المنصة',
                'OWNERSHIP_MOVE_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($membership->role === OrganizationMembership::ROLE_OWNER) {
            $ownersCount = $org->activeMemberships()
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->count();

            if ($ownersCount <= 1) {
                throw new ApiRefused(
                    'لا يمكن إزالة آخر مالك للمنشأة',
                    'CANNOT_REMOVE_LAST_OWNER',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $membership->update(['is_active' => false]);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'ORG_MEMBER_REMOVED',
            'auditable_type' => OrganizationMembership::class,
            'auditable_id' => $membership->id,
            'old_values' => ['role' => $membership->role, 'is_active' => true],
            'new_values' => ['is_active' => false, 'reason' => $reason],
            'organization_id' => $org->id,
            'ip_address' => $ip,
        ]);

        return $membership;
    }

    /**
     * May this account re-staff this company?
     *
     * The membership row is asked, not the role string: `members.manage` is worth
     * whatever the company's standing for this person says it is worth, so a
     * standing the business invented («مدير العمليات») hires and fires exactly as a
     * seeded one does, and a manager at another company does not. The route
     * middleware already asked the same capability of the *tenant* context; this
     * asks it of the company in the path, which is the one being edited.
     *
     * @throws ApiRefused
     */
    public function assertMayStaff(?User $actor, Organization $org, string $refusalMessage): void
    {
        if (!$this->mayStaff($actor, $org)) {
            throw new ApiRefused($refusalMessage, 'FORBIDDEN', Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * May this account rewrite the company's own profile?
     *
     * A different question from staffing, and it stays a different method
     * because the answer is different: the profile is the name, the registration
     * number and the trading identity the marketplace shows to counterparties,
     * and only the owner and the company's admins are trusted with it — a
     * standing granted `members.manage` may hire and fire without being entitled
     * to rename the business.
     *
     * Asked before the form is read, by the door that calls this: an account with
     * no right to edit is told that, and not that its city field was too long.
     *
     * @throws ApiRefused
     */
    public function assertMayEditProfile(?User $actor, Organization $org): void
    {
        // `admin` is written as the column stores it: the platform names the
        // standings in config, and only `owner` carries a constant because it is
        // the one every other rule in this class is about.
        $standing = $actor?->roleInOrganization($org->id);

        if ($actor !== null
            && ($actor->isPlatformOperator()
                || $standing === OrganizationMembership::ROLE_OWNER
                || $standing === 'admin')) {
            return;
        }

        throw new ApiRefused(
            'تعديل ملف المنشأة للمالك أو مدير المنشأة فقط',
            'FORBIDDEN',
            Response::HTTP_FORBIDDEN
        );
    }

    private function mayStaff(?User $actor, Organization $org): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->isPlatformOperator()) {
            return true;
        }

        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->first();

        return $membership !== null && $membership->covers('members.manage');
    }

    /** Does this edit put ownership on somebody, or take it off them? */
    private function touchesOwnership(string $newRole, ?string $currentRole): bool
    {
        return $newRole !== $currentRole
            && in_array(OrganizationMembership::ROLE_OWNER, [$newRole, (string) $currentRole], true);
    }

    /**
     * Ownership is the standing that decides who can change every other
     * standing in a company, so it moves only by the decision of whoever holds
     * it, or of the platform.
     */
    private function mayMoveOwnership(?User $actor, Organization $org): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->isPlatformOperator()) {
            return true;
        }

        return $actor->roleInOrganization($org->id) === OrganizationMembership::ROLE_OWNER;
    }

    /** Is this edit the one that leaves the organization ownerless? */
    private function wouldLeaveWithoutOwner(OrganizationMembership $membership, array $validated): bool
    {
        if ($membership->role !== OrganizationMembership::ROLE_OWNER) {
            return false;
        }

        $staysOwner = $validated['role'] === OrganizationMembership::ROLE_OWNER
            && (bool) ($validated['is_active'] ?? true);

        if ($staysOwner) {
            return false;
        }

        return OrganizationMembership::where('organization_id', $membership->organization_id)
                ->where('id', '!=', $membership->id)
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->where('is_active', true)
                ->count() === 0;
    }
}
