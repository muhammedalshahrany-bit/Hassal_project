<?php

namespace App\Organizations\Services;

use App\Core\Models\AuditLog;
use App\Identity\Models\User;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMemberPermission;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Models\OrganizationRole;
use App\Organizations\Support\PermissionMatrixResolver;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mechanics of editing one company's permission matrix.
 *
 * The controller decides who may knock (staffing permission), reads the form
 * (request validation) and answers in the client's envelope; this class decides
 * what a write actually means: which keys a standing may take, what copying
 * from another standing copies, which edits the owner protection refuses, and
 * what the audit row records. It speaks in `ApiRefused` and plain
 * arrays, never in JsonResponses, so the same rules stand behind any future
 * door — console, bulk tool, or import — not only the one HTTP route.
 */
class OrganizationRoleEditingService
{
    /**
     * The company's standings, each with what it is worth and who holds it.
     *
     * @return array<string, mixed>
     */
    public function listRoles(Organization $org): array
    {
        $this->provisionIfMissing($org);

        $roles = OrganizationRole::forOrganization($org->id);

        $counts = DB::table('organization_memberships')
            ->where('organization_id', $org->id)
            ->groupBy('role')
            ->select('role', DB::raw('count(*) as holders'))
            ->pluck('holders', 'role');

        return [
            'organization_id' => $org->id,
            // Only the names this company may be given a standing for. A screen
            // that renders the platform's capabilities as greyed-out boxes is a
            // screen that gets asked why they are greyed out; the list simply does
            // not contain them, and `scope` says which side each name belongs to
            // for the ones that do.
            'grantable_permissions' => PermissionMatrixResolver::grantablePermissions(),
            'roles' => array_map(
                fn (OrganizationRole $role): array => $this->rolePayload($role, (int) ($counts[$role->key] ?? 0)),
                $roles
            ),
        ];
    }

    /**
     * Give a company its own name for a standing.
     *
     * A custom standing starts from the platform default it is modelled on, when
     * the caller names one, and from nothing otherwise. Copying is the honest
     * default for «نائب المدير» and a trap for «أمين المستودع», so the caller
     * chooses and the choice is auditable.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>  the created role, in wire shape
     *
     * @throws ApiRefused  ROLE_KEY_INVALID / ROLE_KEY_TAKEN / ROLE_KEY_RESERVED /
     *                              ROLE_PERMISSION_REFUSED / ROLE_SOURCE_MISSING
     */
    public function createRole(Organization $org, array $validated, User $actor, string $ip): array
    {
        [$accepted, $why] = OrganizationRole::validateKey($validated['key']);

        if (!$accepted) {
            throw new ApiRefused($why, 'ROLE_KEY_INVALID', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (OrganizationRole::existsForKey($org->id, $validated['key'])) {
            throw new ApiRefused(
                'لدي هذه المنشأة دور بهذا المفتاح بالفعل',
                'ROLE_KEY_TAKEN',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The five standings the platform names belong to every company and are
        // re-provisioned on read; a custom key may not collide with one, or the
        // same word would mean two different things depending on whether this
        // company had been provisioned yet.
        if (OrganizationMembership::isRole($validated['key'])) {
            throw new ApiRefused(
                'هذا المفتاح اسم دور معتمد من المنصة؛ استخدم مفتاحًا آخر',
                'ROLE_KEY_RESERVED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $resolved = PermissionMatrixResolver::wanted($validated);

        if ($resolved['refused'] !== []) {
            throw $this->permissionRefusal($resolved['refused']);
        }

        $role = OrganizationRole::create([
            'organization_id' => $org->id,
            'key' => $validated['key'],
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?? null,
            'is_system' => false,
        ]);

        if ($validated['permissions'] === null && !empty($validated['copy_from'])) {
            $source = OrganizationRole::lookup($org->id, $validated['copy_from']);

            if ($source === null) {
                // The row was only ever a scaffold for the copy; a standing
                // born of a source that does not exist must not survive it.
                $role->delete();

                throw new ApiRefused(
                    'الوقوف المنقول عنه غير موجود في هذه المنشأة',
                    'ROLE_SOURCE_MISSING',
                    Response::HTTP_NOT_FOUND
                );
            }

            $role->permissions()->sync($source->permissions->pluck('id')->all());
        } else {
            $role->permissions()->sync(PermissionMatrixResolver::permissionIds($resolved['names']));
        }

        $this->audit($actor, $org->id, $role->id, 'ORG_ROLE_CREATED', $ip, null, [
            'key' => $role->key,
            'permissions' => $role->permissionNames(),
            'reason' => $validated['reason'],
        ]);

        // Every write in this class ends with the same line, and that is the
        // point: a matrix change whose cached resolutions outlive it has not
        // happened yet as far as the next request is concerned, and a door that
        // has to remember to say so is a door that eventually will not.
        OrganizationMembership::flushResolutions();

        return $this->rolePayload($role);
    }

    /**
     * Rename a standing or replace the capabilities it carries.
     *
     * The grants are replaced, not merged. A matrix editor shows checkboxes and
     * the state of the row after a save is expected to be the state of the screen
     * when it was submitted; an additive save would mean a box cannot be unticked.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null  the updated role, or null when the standing does not exist here
     *
     * @throws ApiRefused  ROLE_PERMISSION_REFUSED / OWNER_STANDING_PROTECTED
     */
    public function updateRole(Organization $org, string $key, array $validated, User $actor, string $ip): ?array
    {
        $role = OrganizationRole::lookup($org->id, $key);

        if ($role === null) {
            return null;
        }

        $before = $role->permissionNames();

        if (array_key_exists('permissions', $validated)) {
            $resolved = PermissionMatrixResolver::wanted($validated);

            if ($resolved['refused'] !== []) {
                throw $this->permissionRefusal($resolved['refused']);
            }

            if ($role->key === OrganizationRole::KEY_OWNER) {
                $missing = array_values(array_diff(PermissionMatrixResolver::OWNER_CORE, $resolved['names']));

                if ($missing !== []) {
                    throw new ApiRefused(
                        'لا يمكن تجريده من: ' . implode(', ', $missing) . ' — المالك هو من يعيّن غيرهم',
                        'OWNER_STANDING_PROTECTED',
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            }

            $refused = $role->replacePermissions($resolved['names']);

            if ($refused !== []) {
                throw new ApiRefused(
                    'لا تُمنح داخل المنشأة: ' . implode(', ', $refused),
                    'ROLE_PERMISSION_REFUSED',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $role->fill(array_filter([
            'name_ar' => $validated['name_ar'] ?? null,
            'name_en' => $validated['name_en'] ?? null,
        ], static fn (?string $value): bool => $value !== null))->save();

        $this->audit($actor, $org->id, $role->id, 'ORG_ROLE_UPDATED', $ip, ['permissions' => $before], [
            'permissions' => $role->permissionNames(),
            'name_ar' => $role->name_ar,
            'reason' => $validated['reason'],
        ]);

        OrganizationMembership::flushResolutions();

        return $this->rolePayload($role);
    }

    /**
     * Retire a standing the company invented.
     *
     * The five the platform names stay: `organization_memberships.role` holds a
     * string, and a company that deleted `staff` would keep rows naming it. A
     * custom standing goes only when nobody holds it — deleting one with holders
     * would leave them with a key that resolves to nothing, and the read path
     * falls back to the platform default for such a row, which is the opposite of
     * what an editor who just deleted it means.
     *
     * @return array<string, mixed>|null  the deleted snapshot, or null when the standing does not exist here
     *
     * @throws ApiRefused  ROLE_SYSTEM_PROTECTED / ROLE_IN_USE
     */
    public function deleteRole(Organization $org, string $key, string $reason, User $actor, string $ip): ?array
    {
        $role = OrganizationRole::lookup($org->id, $key);

        if ($role === null) {
            return null;
        }

        if ($role->is_system) {
            throw new ApiRefused(
                'دور معتمد من المنصة لا يُحذف: يمكن تعديل صلاحياته فقط',
                'ROLE_SYSTEM_PROTECTED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $holders = OrganizationMembership::where('organization_id', $org->id)
            ->where('role', $role->key)
            ->count();

        if ($holders > 0) {
            throw new ApiRefused(
                "يزال {$holders} من أعضاء المنشأة يحملون هذا الدور؛ انقلهم أولًا",
                'ROLE_IN_USE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['holders' => $holders]
            );
        }

        $snapshot = [
            'key' => $role->key,
            'name_ar' => $role->name_ar,
            'permissions' => $role->permissionNames(),
        ];

        $role->delete();

        $this->audit($actor, $org->id, $role->id, 'ORG_ROLE_DELETED', $ip, $snapshot, ['reason' => $reason]);

        OrganizationMembership::flushResolutions();

        return $snapshot;
    }

    /**
     * One member's individual grants and denials, beside what their standing gives.
     *
     * @return array<string, mixed>
     */
    public function memberPayload(OrganizationMembership $membership): array
    {
        return [
            'user_id' => (int) $membership->user_id,
            'organization_id' => (int) $membership->organization_id,
            'role' => $membership->role,
            'is_active' => $membership->is_active,
            'role_permissions' => OrganizationRole::lookup($membership->organization_id, $membership->role)
                ?->permissionNames() ?? OrganizationMembership::permissionsFor($membership->role),
            'grants' => PermissionMatrixResolver::overrideNames($membership, OrganizationMemberPermission::EFFECT_GRANT),
            'denials' => PermissionMatrixResolver::overrideNames($membership, OrganizationMemberPermission::EFFECT_DENY),
            // What the account can actually do here — the composition the runtime
            // applies, computed by the same method the enforcement path uses, so
            // the screen cannot show an answer the middleware disagrees with.
            'effective' => $membership->effectivePermissions(),
        ];
    }

    /**
     * A name may not be both added to and taken from the same person.
     *
     * The rule is here rather than in the form reader because it is a rule about
     * the decision, not about the request: an override set that contains one name
     * twice, with opposite effects, has no meaning until the server picks a
     * precedence, and a platform whose answer depends on which loop reached the
     * row first is a platform whose access checks are decided by iteration order.
     * Refusing it outright keeps the precedence question from ever being asked —
     * and it is asked of every writer of overrides, so a door that forgot to ask
     * it cannot become the second opinion.
     *
     * @param  list<string>  $grants
     * @param  list<string>  $denials
     *
     * @throws ApiRefused
     */
    private function assertNoContradiction(array $grants, array $denials): void
    {
        $both = array_values(array_intersect($grants, $denials));

        if ($both !== []) {
            throw new ApiRefused(
                'لا يمكن منح الصلاحية وسحبها للعضو نفسه في الوقت نفسه: ' . implode(', ', $both),
                'MEMBER_PERMISSION_CONTRADICTION',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['permissions' => $both]
            );
        }
    }

    /**
     * Replace one person's override rows.
     *
     * Rows are replaced rather than synced through a relation the model does not
     * own as a belongsToMany: `effect` is part of the identity of a row, and a
     * pivot sync that cannot express it would leave a stale `deny` standing
     * where a `grant` was just written.
     *
     * @param  list<string>  $grants
     * @param  list<string>  $denials
     *
     * @throws ApiRefused  MEMBER_PERMISSION_CONTRADICTION / MEMBER_PERMISSION_REFUSED / OWNER_STANDING_PROTECTED
     */
    public function writeMemberOverrides(
        OrganizationMembership $membership,
        array $grants,
        array $denials,
        string $reason,
        User $actor,
        string $ip
    ): void {
        $this->assertNoContradiction($grants, $denials);

        $resolved = PermissionMatrixResolver::filter([...$grants, ...$denials]);

        if ($resolved['refused'] !== []) {
            throw new ApiRefused(
                'لا تُمنح ولا تُسحب داخل المنشأة: ' . implode(', ', $resolved['refused']),
                'MEMBER_PERMISSION_REFUSED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['permissions' => $resolved['refused']]
            );
        }

        if ($membership->role === OrganizationMembership::ROLE_OWNER) {
            $stripped = array_values(array_intersect(PermissionMatrixResolver::OWNER_CORE, $denials));

            if ($stripped !== []) {
                throw new ApiRefused(
                    'لا يُجرَّد المالك من: ' . implode(', ', $stripped),
                    'OWNER_STANDING_PROTECTED',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['permissions' => $stripped]
                );
            }
        }

        $ids = PermissionMatrixResolver::permissionIds([...$grants, ...$denials]);

        $membership->overrides()->delete();

        foreach ($grants as $name) {
            if (isset($ids[$name])) {
                $membership->overrides()->create([
                    'permission_id' => $ids[$name],
                    'effect' => OrganizationMemberPermission::EFFECT_GRANT,
                ]);
            }
        }

        foreach ($denials as $name) {
            if (isset($ids[$name])) {
                $membership->overrides()->create([
                    'permission_id' => $ids[$name],
                    'effect' => OrganizationMemberPermission::EFFECT_DENY,
                ]);
            }
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'ORG_MEMBER_PERMISSIONS_UPDATED',
            'auditable_type' => OrganizationRole::class,
            'auditable_id' => $membership->id,
            'old_values' => null,
            'new_values' => ['user_id' => (int) $membership->user_id, 'grants' => $grants, 'denials' => $denials, 'reason' => $reason],
            'organization_id' => $membership->organization_id,
            'ip_address' => $ip,
        ]);

        // The write only means anything once the resolutions a caller was
        // consulted through are thrown away, so it happens here rather than at
        // every door: a permission change whose cache outlives it is a change
        // that has not happened yet.
        OrganizationMembership::flushResolutions();
    }

    /**
     * The same decision applied to several people at once.
     *
     * Bulk exists because a company of forty does not want forty screens to reach
     * the same answer, and because an edit applied to a group is one audit entry
     * with one reason rather than forty that no longer say why. Every member row
     * is checked on its own, so a request touching a company the caller does not
     * run fails for that id and not for the whole call.
     *
     * The whole batch is refused when any one of it is: a screen that reports
     * "granted to everybody except Ali" cannot be re-submitted safely, and a
     * half-applied permission change is the one outcome neither the editor nor
     * the audit log can be trusted to describe. The rows written before the
     * refusal already stand, so the resolutions are flushed on the way out of
     * the refusal too — the cache must not keep answering as if the batch had
     * never touched the table.
     *
     * @param  list<int|string>  $userIds
     * @param  list<string>      $grants
     * @param  list<string>      $denials
     *
     * @return array{organization_id: int, applied: list<array<string, mixed>>}
     *
     * @throws ApiRefused  MEMBER_PERMISSION_CONTRADICTION, MEMBERSHIP_NOT_FOUND and whatever one member's write refuses
     */
    public function writeBulkMemberOverrides(
        int|string $organizationId,
        array $userIds,
        array $grants,
        array $denials,
        string $reason,
        User $actor,
        string $ip
    ): array {
        // Asked before the roster, as the single-member door asks it: a request
        // that contradicts itself is malformed whatever it says about who is in
        // the company, and answering it with the membership question would send
        // the editor off to fix a roster that was never the problem.
        $this->assertNoContradiction($grants, $denials);

        $memberships = OrganizationMembership::where('organization_id', $organizationId)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $missing = array_values(array_diff($userIds, $memberships->keys()->all()));

        if ($missing !== []) {
            throw new ApiRefused(
                'ليسوا أعضاء في هذه المنشأة: ' . implode(', ', $missing),
                'MEMBERSHIP_NOT_FOUND',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['user_ids' => $missing]
            );
        }

        $applied = [];

        try {
            foreach ($memberships as $membership) {
                $before = [
                    'grants' => PermissionMatrixResolver::overrideNames($membership, OrganizationMemberPermission::EFFECT_GRANT),
                    'denials' => PermissionMatrixResolver::overrideNames($membership, OrganizationMemberPermission::EFFECT_DENY),
                ];

                $this->writeMemberOverrides($membership, $grants, $denials, $reason, $actor, $ip);

                $applied[] = [
                    'user_id' => (int) $membership->user_id,
                    'grants' => $grants,
                    'denials' => $denials,
                    'changed' => $before['grants'] !== $grants || $before['denials'] !== $denials,
                ];
            }
        } catch (ApiRefused $refused) {
            // The members before the refusal were written for real.
            OrganizationMembership::flushResolutions();

            throw $refused;
        }

        return [
            'organization_id' => (int) $organizationId,
            'applied' => $applied,
        ];
    }

    /**
     * One standing the company holds, for a member of this company only.
     */
    public function membershipFor(int|string $organizationId, int|string $userId): ?OrganizationMembership
    {
        return OrganizationMembership::where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * The staffing vocabulary a screen may offer.
     *
     * Returned rather than typed into the client because the same list is what
     * the validator accepts; a dropdown built from a client-side copy is a 422
     * waiting for the next rename.
     *
     * Inside a company context it answers with *that company's* standings — the
     * five the platform names as they now exist in its own rows, edited to
     * whatever worth the business gave them, plus the ones it named for itself.
     * Without a context there is no company to read, so the platform defaults are
     * what a screen may offer; they are also what an unprovisioned company is
     * read against, so the two never disagree about a company that has not
     * edited anything yet.
     *
     * @return list<array<string, mixed>>
     */
    public function staffingVocabulary(?Organization $organization): array
    {
        if ($organization === null) {
            $vocabulary = [];

            foreach (OrganizationMembership::roles() as $name => $label) {
                $permissions = OrganizationMembership::permissionsFor($name);

                $vocabulary[] = [
                    'value' => $name,
                    'label' => $label,
                    'is_system' => true,
                    // Each standing is published with the capabilities it is
                    // worth, because the owner who appoints somebody is making a
                    // real decision with this list: a dropdown that says only
                    // «المالية» cannot show that the person chosen from it will
                    // not be able to move stock.
                    'permission_count' => count($permissions),
                    'permissions' => $permissions,
                ];
            }

            return $vocabulary;
        }

        // The same backfill the role editor performs: a company older than the
        // table is otherwise offered a list of standings it cannot have, since
        // the validator now accepts its own keys as well as these five.
        $this->provisionIfMissing($organization);

        $vocabulary = [];

        foreach (OrganizationRole::forOrganization($organization->id) as $role) {
            $permissions = $role->permissionNames();

            $vocabulary[] = [
                'value' => $role->key,
                'label' => $role->name_ar,
                'is_system' => $role->is_system,
                'permission_count' => count($permissions),
                'permissions' => $permissions,
            ];
        }

        return $vocabulary;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function rolePayload(OrganizationRole $role, ?int $holderCount = null): array
    {
        $payload = [
            'id' => $role->id,
            'key' => $role->key,
            'name_ar' => $role->name_ar,
            'name_en' => $role->name_en,
            'is_system' => $role->is_system,
            'permissions' => $role->permissionNames(),
        ];

        if ($holderCount !== null) {
            $payload['holder_count'] = $holderCount;
        }

        return $payload;
    }

    private function permissionRefusal(array $refused): ApiRefused
    {
        return new ApiRefused(
            'لا تُمنح داخل المنشأة: صلاحيات المنصة وأسماء غير معلنة لا تحملها وقفات الشركات — ' . implode(', ', $refused),
            'ROLE_PERMISSION_REFUSED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['permissions' => $refused]
        );
    }

    /**
     * Backfill the standings for a company older than the table.
     *
     * The migration provisions every company that exists when it runs and the
     * creation path provisions every company created after; a row inserted by a
     * seeder, an import or a direct insert falls between both, and a company with
     * no standing rows reads against the platform defaults — correct, but invisible
     * to the editor that offers "edit this company's roles" and finds none.
     */
    private function provisionIfMissing(Organization $org): void
    {
        if (OrganizationRole::where('organization_id', $org->id)->exists()) {
            return;
        }

        OrganizationRole::provisionOrganization($org->id);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(User $actor, int|string $organizationId, int|string $roleId, string $action, string $ip, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => OrganizationRole::class,
            'auditable_id' => $roleId,
            'old_values' => $old,
            'new_values' => $new,
            'organization_id' => $organizationId,
            'ip_address' => $ip,
        ]);
    }
}
