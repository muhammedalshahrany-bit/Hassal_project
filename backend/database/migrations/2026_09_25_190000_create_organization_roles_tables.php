<?php

use App\Core\Rbac\RbacScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standings a company owns, instead of a vocabulary the platform invented for it.
 *
 * Three tables and one deletion.
 *
 * `organization_roles` is the set a company staffs from: the five standings
 * `config/rbac.php` declares, seeded per company as `is_system`, plus whatever
 * that company names for itself — أمين مستودع who may move stock and never price
 * it, or a purchasing desk that files asks but confirms nothing. A role is owned
 * by one organization and its key is unique inside that organization only, with
 * the key compared case-sensitively: two rows spelling `Admin` and `admin` would
 * be two standings to the database and one to every screen that renders them,
 * which is the failure the membership column's own CHECK was written to avoid.
 *
 * `organization_role_permissions` says what each of those standings is worth, by
 * permission id rather than by name, so the scope column on `permissions` — not a
 * string comparison in a controller — is what decides whether a platform
 * capability may ever be attached here.
 *
 * `organization_membership_permissions` is the individual override: one member,
 * one permission, and an `effect` that says whether this row adds the capability
 * to the member's standing or takes it away. Both directions exist because a
 * company that can only add cannot answer the ordinary request "everybody keeps
 * the standing they have, except this one person stops deleting listings", and an
 * additive-only table answers it by demoting the person, which changes more than
 * was asked.
 *
 * Finally, `organization_memberships_role_is_canonical` is dropped. That CHECK
 * hard-coded the five names into the schema, and once a company may name a
 * standing of its own the vocabulary is rows in a table — a constraint that
 * references another table cannot be expressed as a CHECK in MySQL, so the
 * boundary moves to the write door (`AddMemberRequest`, `updateMember`), where it
 * is checked against the organization's own roles rather than against a global
 * list. What remains in the schema is the foreign key from the override tables
 * and the `RESTRICT` on a role still holding members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_roles', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 64)->collation('utf8mb4_bin');
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            // MySQL refuses an identifier past 64 characters, and the names it
            // derives from these tables would each pass that limit by a few. Every
            // shared constraint is named here instead, explicitly, so the schema
            // stays addressable: a migration that has to drop or replace one later
            // has to be able to say which one.
            $table->unique(['organization_id', 'key'], 'organization_roles_org_key_unique');
        });

        Schema::create('organization_role_permissions', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_role_id')
                ->constrained('organization_roles')
                ->cascadeOnDelete();
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_role_id', 'permission_id'], 'org_role_perms_role_perm_unique');
        });

        Schema::create('organization_membership_permissions', static function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_membership_id');
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();
            $table->string('effect', 8)->default('grant');
            $table->timestamps();

            // Named by hand rather than left to the derived form, which for this
            // table clears sixty-four characters before the grammar sees it.
            $table->foreign('organization_membership_id', 'org_member_perms_membership_fk')
                ->references('id')
                ->on('organization_memberships')
                ->cascadeOnDelete();

            $table->unique(['organization_membership_id', 'permission_id'], 'org_member_perms_member_perm_unique');
        });

        // Case-sensitively, as the standing vocabulary was constrained before:
        // the column's collation would otherwise read `GRANT` as `grant`, and a
        // row nobody can tell apart from the one it contradicts is how an override
        // stops meaning what it says.
        DB::statement("ALTER TABLE `organization_membership_permissions` ADD CONSTRAINT `organization_membership_permissions_effect_is_canonical` CHECK (`effect` COLLATE utf8mb4_bin IN ('grant', 'deny'))");

        $this->seedSystemRoles();
        $this->detachGlobalVocabularyConstraint();
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_membership_permissions');
        Schema::dropIfExists('organization_role_permissions');
        Schema::dropIfExists('organization_roles');

        // Back to the five names written into the schema. Only rebuilt if the
        // column is still holding nothing outside them; a company that had named a
        // standing of its own would otherwise get a CHECK it cannot satisfy.
        $system = array_keys((array) config('rbac.membership_roles'));
        $stray = DB::table('organization_memberships')->whereNotIn('role', $system)->count();

        if ($stray === 0 && $system !== []) {
            $values = implode(',', array_map(
                static fn (string $role): string => "'" . addslashes($role) . "'",
                $system
            ));
            DB::statement("ALTER TABLE `organization_memberships` ADD CONSTRAINT `organization_memberships_role_is_canonical` CHECK (`role` COLLATE utf8mb4_bin IN ({$values}))");
        }
    }

    /**
     * Give every existing company the standings its rows already name.
     *
     * The permissions come from `config/rbac.php` — the same defaults the seeder
     * converges, so a company that never edited anything after this migration
     * reads exactly as it did before it. Only names the company side may hold are
     * written: a standing's declared list in config is a reviewable document, and
     * this is the one place it is copied into rows, so the copy is filtered rather
     * than trusted.
     */
    private function seedSystemRoles(): void
    {
        /** @var array<string, string> $standings */
        $standings = (array) config('rbac.membership_roles');

        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('rbac.membership_permissions');

        $ids = DB::table('permissions')->pluck('id', 'name');

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            foreach ($standings as $key => $label) {
                DB::table('organization_roles')->insertOrIgnore([
                    'organization_id' => $organizationId,
                    'key' => $key,
                    'name_ar' => $label,
                    'name_en' => null,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('organization_roles')
                    ->where('organization_id', $organizationId)
                    ->where('key', $key)
                    ->value('id');

                if ($row === null) {
                    continue;
                }

                foreach ($matrix[$key] ?? [] as $name) {
                    // The write doors would refuse this name; so does the seed that
                    // first fills the table, or the boundary would exist everywhere
                    // except in the one migration that creates the rows.
                    if (!RbacScope::exercisableInsideOrganization($name) || !isset($ids[$name])) {
                        continue;
                    }

                    DB::table('organization_role_permissions')->insertOrIgnore([
                        'organization_role_id' => $row,
                        'permission_id' => $ids[$name],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function detachGlobalVocabularyConstraint(): void
    {
        DB::statement('ALTER TABLE `organization_memberships` DROP CHECK `organization_memberships_role_is_canonical`');
    }
};
