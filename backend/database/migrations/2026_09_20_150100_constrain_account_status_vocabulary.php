<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.status` may only hold an account state the platform acts on.
 *
 * The value decided whether an account could sign in, and three writers agreed
 * on none of it: login compared `status` against the lowercase `active`, the
 * admin validator accepted `SUSPENDED` / `INACTIVE` / `ACTIVE`, and the column
 * had no constraint and no vocabulary. So an administrator who suspended an
 * account through the API stored `SUSPENDED`, which locked the person out while
 * every read surface — `auth/me`, the accounts list, the audit entry — still
 * described the account as ACTIVE. The lockout was real and the record of it was
 * wrong.
 *
 * `config('hassal.accounts.statuses')` is now the single list, read by the
 * validator, by `EnsureAccountIsActive`, and by this constraint. A status that
 * only a docstring knows about is a convention; in the column it is a rule.
 *
 * The comparison is made case-sensitively on purpose. The column's collation is
 * `utf8mb4_unicode_ci`, under which `SUSPENDED` equals `suspended`, so a plain
 * `status IN (...)` constraint would have accepted — and kept — exactly the
 * value this migration exists to remove. `COLLATE utf8mb4_bin` makes the
 * constraint say what the product means: these words, in this spelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var list<string> $statuses */
        $statuses = array_values((array) config('hassal.accounts.statuses'));
        abort_unless($statuses !== [], 1, 'hassal.accounts.statuses is empty; refusing to constrain the column to nothing.');

        // Uppercase states were written by the old validator. Map them by name
        // rather than by row so the meaning survives: SUSPENDED already locked
        // the account out, and INACTIVE already excluded it.
        DB::statement("UPDATE `users` SET `status` = LOWER(`status`) WHERE `status` NOT IN ('" . implode("','", array_map('addslashes', $statuses)) . "')");

        $stray = DB::table('users')
            ->whereNotIn('status', $statuses)
            ->select('id', 'email', 'status')
            ->get();

        if ($stray->isNotEmpty()) {
            logger()->warning(
                'users.status holds values outside hassal.accounts.statuses; these accounts stay unclassified '
                . 'until corrected. Rows: ' . $stray->map(
                    static fn ($row) => "#{$row->id} {$row->email} status={$row->status}"
                )->implode('; ')
            );
        }

        $values = implode(',', array_map(
            static fn (string $status): string => "'" . addslashes($status) . "'",
            $statuses
        ));

        DB::statement("ALTER TABLE `users` MODIFY `status` VARCHAR(50) NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `users_status_is_canonical` CHECK (`status` COLLATE utf8mb4_bin IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `users` DROP CHECK `users_status_is_canonical`');
        DB::statement("ALTER TABLE `users` MODIFY `status` VARCHAR(255) NOT NULL DEFAULT 'active'");
    }
};
