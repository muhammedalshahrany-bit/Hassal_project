<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every organization that has people in it must have one who owns it.
 *
 * The rule is enforced on the way in — `updateMember()` refuses to demote the
 * last active owner, and `OrganizationMembership::ROLE_OWNER` is what a new
 * company's founder is recorded as — but a rule that only guards new writes
 * leaves whatever the older code already produced. Here that is a company whose
 * only senior member had been moved to an internal auditor: readable, staffable
 * by nobody, and with no account that could appoint the next person. The
 * platform could see it in a listing and could not fix it from the company
 * screens, because the screens ask who the owner is and the answer was no one.
 *
 * So the repair is general rather than by row id: an organization with members
 * and no active owner has its longest-serving active member promoted. That is
 * the account that stood between the company and everyone else when the row was
 * written, which is the closest thing the data still has to the founder's
 * intent — and where there is nothing to promote, the migration says so instead
 * of inventing an owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ownerless = DB::table('organizations')
            ->select('id', 'name_ar')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('organization_memberships')
                    ->whereColumn('organization_memberships.organization_id', 'organizations.id')
                    ->where('organization_memberships.role', 'owner')
                    ->where('organization_memberships.is_active', true);
            })
            ->get();

        foreach ($ownerless as $organization) {
            $candidate = DB::table('organization_memberships')
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->orderBy('joined_at')
                ->orderBy('id')
                ->first();

            if (!$candidate) {
                logger()->warning(
                    "Organization #{$organization->id} ({$organization->name_ar}) has no members at all, "
                    . 'so it cannot be given an owner. Nobody may staff it until a platform admin adds one.'
                );

                continue;
            }

            DB::table('organization_memberships')
                ->where('id', $candidate->id)
                ->update(['role' => 'owner']);

            logger()->info(
                "Organization #{$organization->id} ({$organization->name_ar}) had no active owner; "
                . "membership #{$candidate->id} (user #{$candidate->user_id}, was {$candidate->role}) was promoted to owner."
            );

            // Ownership is the one standing that must not be handed out by the
            // screens below, so the change is written to the same ledger every
            // other role decision goes to — with no actor, because an upgrade
            // step is the actor and should not pretend otherwise.
            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'user_id' => null,
                'action' => 'ORG_MEMBERSHIP_OWNER_REPAIRED',
                'auditable_type' => 'App\Organizations\Models\OrganizationMembership',
                'auditable_id' => $candidate->id,
                'old_values' => json_encode(['role' => $candidate->role]),
                'new_values' => json_encode([
                    'role' => 'owner',
                    'reason' => 'منشأة بلا مالك نشط؛ رقّتها هجرةُ ترقية، لا قرارُ مستخدم',
                ]),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The promotion is not undone: putting a company back into a state where
        // nobody can appoint anyone is a worse place to land than the one this
        // migration left. `audit_logs` records who moved, and the row before it
        // is in the log's `old_values`.
    }
};
