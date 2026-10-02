<?php

namespace App\Console\Commands;

use App\Platform\Models\FeatureFlag;
use App\Platform\Services\FeatureFlagService;
use App\Platform\Support\CatalogSync;
use App\Platform\Support\FlagCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile the `feature_flags` table with App\Platform\Support\FlagCatalog.
 *
 * Same failure this exists for as with the settings table: the catalog and the
 * installed rows drifted apart. This deployment had no `marketplace` row, and
 * the feature middleware treats a missing flag as off — so every seller route
 * behind `feature:marketplace` answered FEATURE_DISABLED while the admin console
 * still listed `offer_review_required` and `partial_fulfillment`, two switches
 * that gate nothing and were deliberately removed from the catalog. A dead
 * switch in a console is worse than no switch: it teaches the operator that the
 * live ones may be dead too.
 *
 * A flag already in the table keeps its value: flipping a capability on or off
 * is the operator's call, not a side effect of re-running a sync.
 */
class SyncFeatureFlags extends Command
{
    protected $signature = 'features:sync-catalog
                            {--retire : Delete flags that are not in the catalog, after printing them}';

    protected $description = 'Insert catalog feature flags the database is missing and report flags the catalog does not know';

    public function handle(FeatureFlagService $flags): int
    {
        $catalogKeys = array_column(FlagCatalog::defaults(), 'key');
        $stored = FeatureFlag::query()->get()->keyBy('key');

        $inserted = [];
        $kept = [];

        foreach (FlagCatalog::defaults() as $row) {
            if ($stored->has($row['key'])) {
                $kept[] = $row['key'];
                continue;
            }

            CatalogSync::insertFlag($row);
            $inserted[] = $row['key'] . ($row['is_enabled'] ? ' (on)' : ' (off)');
        }

        $this->info(sprintf('Catalog flags: %d  ·  already stored: %d  ·  inserted now: %d',
            count($catalogKeys), count($kept), count($inserted)));

        foreach ($inserted as $key) {
            $this->line("  + {$key}");
        }

        $orphans = $stored->keys()->reject(fn ($key) => in_array($key, $catalogKeys, true))->values();

        if ($orphans->isNotEmpty()) {
            $this->warn($orphans->count() . ' stored flag(s) are not in the catalog and gate no code path:');
            foreach ($orphans as $key) {
                $this->line("    - {$key} = " . ($stored[$key]->is_enabled ? 'on' : 'off'));
            }

            if ($this->option('retire')) {
                DB::table('feature_flags')->whereIn('key', $orphans->all())->delete();
                $this->info('  retired.');
            } else {
                $this->line('    Left in place. Re-run with --retire once they have been reviewed.');
            }
        }

        // The service resolves flags through a one-hour cache. Without this,
        // a flag added here stays invisible — and an invisible flag fails
        // closed, so `marketplace` would keep answering FEATURE_DISABLED.
        $flags->flushCache();
        $this->info('Flag cache cleared; the new state is live.');

        return self::SUCCESS;
    }
}
