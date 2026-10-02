<?php

namespace App\Console\Commands;

use App\Platform\Models\SystemSetting;
use App\Platform\Support\CatalogSync;
use App\Platform\Support\SettingCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile the `system_settings` table with App\Platform\Support\SettingCatalog.
 *
 * Why this exists as its own command: the installer seeds the catalog once, on
 * a fresh database. A platform that was installed before the catalog existed —
 * or that gained keys afterwards — keeps a settings table that no longer matches
 * the vocabulary the code reads. That is not cosmetic. This deployment's table
 * had `platform.commission_rate` (set to 0.025) and `platform.tax_rate`, while
 * the pricing service reads `commission.rate` and `tax.rate`; an operator
 * editing the commission in the admin console was editing a number nothing
 * consumed, and a real 2.5% charge could not be turned off from that screen
 * either, because no screen could see it.
 *
 * Rules, and why each one is what it is:
 *   * A catalog key that is missing is inserted at its default. The platform
 *     cannot run with a setting no one has ever stored.
 *   * A catalog key that exists is left alone. Its value is an operator's
 *     decision, and re-running this command must not undo a configuration.
 *   * A stored key that is not in the catalog is *reported*, never deleted by
 *     default. Some of them hold a number somebody chose, and the honest move
 *     is to show it to them and let them say what replaces it. `--retire`
 *     deletes the orphans once that has been reviewed, and only after their
 *     values have been dumped so the choice is not lost.
 */
class SyncSettingsCatalog extends Command
{
    protected $signature = 'settings:sync-catalog
                            {--retire : Delete settings that are not in the catalog, after printing them}
                            {--json : Print the reconciliation as JSON}';

    protected $description = 'Insert catalog settings the database is missing and report keys the catalog does not know';

    public function handle(): int
    {
        $catalogKeys = array_column(SettingCatalog::defaults(), 'key');
        $stored = SystemSetting::query()->whereNull('organization_id')->get()->keyBy('key');

        $inserted = [];
        $kept = [];
        $orphaned = [];

        foreach (SettingCatalog::defaults() as $row) {
            if ($stored->has($row['key'])) {
                $kept[] = $row['key'];
                continue;
            }

            // Through CatalogSync, because the row has to arrive with the type,
            // group, descriptions and public flag the catalog declares. Created
            // any other way it lands in the console as a bare key with no
            // description, and a switch nobody can read is a switch nobody can
            // operate.
            CatalogSync::insertSetting($row);
            $inserted[] = $row['key'];
        }

        foreach ($stored as $key => $setting) {
            if (!in_array($key, $catalogKeys, true)) {
                $orphaned[] = [
                    'key'   => $key,
                    'value' => $setting->value,
                    'group' => $setting->group,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'inserted' => $inserted,
                'kept'     => $kept,
                'orphaned' => $orphaned,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf('Catalog keys: %d  ·  already stored: %d  ·  inserted now: %d',
                count($catalogKeys), count($kept), count($inserted)));

            foreach ($inserted as $key) {
                $this->line("  + {$key}");
            }
        }

        if ($orphaned !== []) {
            $this->warn(count($orphaned) . ' stored setting(s) are not in the catalog and are read by no code:');
            foreach ($orphaned as $row) {
                $this->line("    - {$row['key']} = {$row['value']}  (group {$row['group']})");
            }

            if ($this->option('retire')) {
                DB::table('system_settings')
                    ->whereIn('key', array_column($orphaned, 'key'))
                    ->delete();
                $this->info('  retired.');
            } else {
                $this->line('    Left in place. Re-run with --retire once these values have been reviewed;');
                $this->line('    a rate that is only stored here cannot be edited or switched off from the console.');
            }
        }

        return self::SUCCESS;
    }
}
