<?php

/**
 * PHPUnit bootstrap — the autoload plus one pre-flight check.
 *
 * `php artisan config:cache` writes `bootstrap/cache/config.php`, and Laravel
 * then serves *all* configuration from that file: `.env`, and with it the
 * `APP_ENV` and `DB_DATABASE` this suite's `phpunit.xml` injects, are never read
 * again. The result is a test run that believes it is in `production` against
 * `hassal_production`.
 *
 * Two defences already exist and neither catches that on time. `Tests\TestCase`
 * refuses a database that is not a test schema — but its guard runs *after*
 * `parent::setUp()`, and `RefreshDatabase` issues `migrate:fresh` inside
 * `parent::setUp()`, so the schema question is asked too late to matter. And
 * `migrate:fresh` confirms before dropping in a production environment — which
 * is what actually saved the live schema, by aborting every test with a Mockery
 * error about `askQuestion()` instead of deleting anything. Forty-four failures
 * with zero assertions, and nothing in the message naming the cache file.
 *
 * So the check belongs here, before the first test class boots the framework:
 * a cached config is not a state a test run may silently inherit. The fix is
 * one command, and it is named in the message rather than left to be guessed.
 */

require __DIR__.'/../vendor/autoload.php';

$configCache = __DIR__.'/../bootstrap/cache/config.php';

if (is_file($configCache)) {
    throw new RuntimeException(
        'Test bootstrap: a cached configuration file exists at ['.$configCache.'].'."\n\n"
        .'While it is present Laravel ignores .env and phpunit.xml alike, so this run would '
        .'boot as [production] against the schema named in the cache instead of [testing] '
        .'against the test schema — and this suite truncates data. It may not inherit that '
        .'decision from a file left behind by a deploy.'."\n\n"
        .'Run `php artisan config:clear` before testing.'
    );
}
