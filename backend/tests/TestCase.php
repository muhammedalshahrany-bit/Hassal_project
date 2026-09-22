<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Substrings that identify a schema as safe to truncate.
     *
     * Tests in this suite use RefreshDatabase, which issues a real
     * `migrate:fresh` against the resolved connection. Historically the
     * database name was inherited from .env, where it is `hassal_production`,
     * so a normal `php artisan test` silently wiped the live schema.
     * This guard makes that impossible rather than merely unlikely.
     */
    private const ALLOWED_DATABASE_SUFFIXES = ['_test', '_testing'];

    /**
     * Databases that must never be a test target, even if renamed to match.
     */
    private const FORBIDDEN_DATABASES = ['hassal_production', 'production', 'laravel'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstNonTestDatabase();
    }

    /**
     * Abort the run unless the active database is an isolated test schema.
     *
     * @throws RuntimeException when the resolved database is not a test database
     */
    private function guardAgainstNonTestDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if ($database === null) {
            throw new RuntimeException(
                'Test guard: no database is resolved for the default connection. '
                . 'Refusing to run tests that could truncate an unintended schema.'
            );
        }

        $normalized = strtolower($database);

        if (in_array($normalized, self::FORBIDDEN_DATABASES, true)) {
            throw new RuntimeException(
                "Test guard: refusing to run against database [{$database}]. "
                . 'This suite truncates data via RefreshDatabase. '
                . 'Point DB_DATABASE at a dedicated test schema (see phpunit.xml).'
            );
        }

        $isTestSchema = str_starts_with($normalized, 'tmp')
            || str_ends_with($normalized, self::ALLOWED_DATABASE_SUFFIXES[0])
            || str_ends_with($normalized, self::ALLOWED_DATABASE_SUFFIXES[1]);

        if (! $isTestSchema) {
            throw new RuntimeException(
                "Test guard: database [{$database}] is not recognisable as a test schema. "
                . 'Expected a name ending in _test or _testing.'
            );
        }
    }

    /**
     * Assert which fields the API rejected.
     *
     * The envelope puts validation details under `error.details` (see
     * bootstrap/app.php), not Laravel's default `errors` key, so
     * assertJsonValidationErrors() cannot see them — a test that used it was
     * checking a response shape the product never sends.
     *
     * @param  array<int, string>  $fields
     */
    protected function assertApiValidationErrors(TestResponse $response, array $fields): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $details = $response->json('error.details');

        $this->assertIsArray($details, 'The 422 carried no validation details object: ' . json_encode($details, JSON_UNESCAPED_UNICODE));

        foreach ($fields as $field) {
            $this->assertArrayHasKey(
                $field,
                $details,
                "No validation failure reported for [{$field}]. Details: " . json_encode($details, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * The first message the API sent for one field, for tests that have to
     * prove *why* something was refused and not merely that it was.
     */
    protected function apiValidationMessage(TestResponse $response, string $field): string
    {
        $details = $response->json('error.details') ?? [];

        // "events.0" is a single key containing a dot, so a dot-notation lookup
        // would search for events → 0 and find nothing.
        $messages = $details[$field] ?? data_get($details, $field);

        $this->assertNotEmpty($messages, "No validation message recorded for [{$field}].");

        return (string) (is_array($messages) ? reset($messages) : $messages);
    }
}
