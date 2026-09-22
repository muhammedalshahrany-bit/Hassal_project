<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `organization_memberships.role` may only hold the standings the platform
 * defines, and its default must be one of them.
 *
 * Before this, the column agreed with nothing:
 *
 *   * the schema defaulted to `operator`,
 *   * `AddMemberRequest` and `updateMember` accepted
 *     `owner,admin,operator,finance,auditor`,
 *   * the rows actually stored `owner`, `staff` and `auditor`.
 *
 * `staff` was the common case in the data and the one value no validator
 * accepted, so a member list could be read but the person in it could never be
 * saved again without a 422 — and an admin who tried got the misleading message
 * that their own roster was invalid. A default of `operator` meant any row
 * created outside Eloquent started life outside the accepted vocabulary.
 *
 * `operator` maps to `staff`: same rank, one word instead of two. The CHECK
 * constraint is the point. A vocabulary that lives only in a PHP docstring is a
 * convention that the next writer can break silently; in the column definition
 * it is a rule, and the violation surfaces at the database.
 *
 * The value set is read from `config('rbac.membership_roles')` rather than
 * restated here, so the constraint, the validators and the member screens can
 * never disagree again.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var list<string> $roles */
        $roles = array_keys((array) config('rbac.membership_roles'));
        abort_unless($roles !== [], 1, 'rbac.membership_roles is empty; refusing to constrain the column to nothing.');

        DB::statement("UPDATE `organization_memberships` SET `role` = 'staff' WHERE `role` = 'operator'");

        // Anything still outside the vocabulary is named in the log rather than
        // silently rewritten: guessing a person's standing inside somebody
        // else's business is not this migration's decision to make.
        $stray = DB::table('organization_memberships')
            ->whereNotIn('role', $roles)
            ->select('id', 'organization_id', 'user_id', 'role')
            ->get();

        if ($stray->isNotEmpty()) {
            logger()->warning(
                'organization_memberships.role holds values outside rbac.membership_roles; '
                . 'the CHECK constraint below will reject writes to these rows until they are corrected. '
                . 'Rows: ' . $stray->map(
                    static fn ($row) => "#{$row->id} org={$row->organization_id} user={$row->user_id} role={$row->role}"
                )->implode('; ')
            );
        }

        // The default has to satisfy the constraint, so it is changed first and
        // widened to the least-privileged standing in the vocabulary.
        DB::statement('ALTER TABLE `organization_memberships` MODIFY `role` VARCHAR(50) NOT NULL DEFAULT \'staff\'');

        $values = implode(',', array_map(
            static fn (string $role): string => "'" . addslashes($role) . "'",
            $roles
        ));

        // Case-sensitively, because the column's collation would otherwise read
        // `OWNER` as `owner` and let a differently-spelled standing through.
        DB::statement("ALTER TABLE `organization_memberships` ADD CONSTRAINT `organization_memberships_role_is_canonical` CHECK (`role` COLLATE utf8mb4_bin IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `organization_memberships` DROP CHECK `organization_memberships_role_is_canonical`');
        DB::statement("ALTER TABLE `organization_memberships` MODIFY `role` VARCHAR(50) NOT NULL DEFAULT 'operator'");
    }
};
