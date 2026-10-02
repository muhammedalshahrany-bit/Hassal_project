<?php

namespace App\Auth\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Auth\Support\PreferenceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What one account wants the product to look and behave like.
 *
 * There is no permission on these routes and that is the point: `GET /auth/preferences`
 * reads the caller's own row and `PUT` writes it, so the only person allowed to
 * set them is the person they are set for. Scoping is by `$request->user()`,
 * never by an id in the path — an endpoint that took a user id here would be an
 * impersonation bug waiting for a forgotten `abort_if`.
 *
 * The vocabulary lives in `PreferenceCatalog`, not in a `validate()` array
 * repeated per client. A key nobody declared is dropped and named in the
 * response rather than stored, which keeps the JSON column a preference map
 * instead of a cache some screen decided to park in the database.
 */
class PreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'preferences' => $request->user()->preferences(),
            // Sent alongside the values so a client builds its form from the
            // server's list rather than from a hardcoded mirror of it — the
            // failure mode being a switch the app renders that the platform has
            // since retired.
            'catalog' => $this->catalogRows(),
        ], 'Preferences retrieved successfully');
    }

    /**
     * Apply a partial update.
     *
     * `preferences` is a patch, not a replacement: a client that knows about two
     * switches must not blank the other seven by omitting them, which is what a
     * settings sheet with two visible rows would otherwise do on every save.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['present'],
        ]);

        $user = $request->user();
        $result = PreferenceCatalog::merge(
            is_array($user->preferences) ? $user->preferences : [],
            $validated['preferences']
        );

        // The whole merged map is written rather than the patch, so a row is
        // always self-describing — and a key retired from the catalog disappears
        // the next time this account saves anything.
        $user->preferences = $result['preferences'];
        $user->save();

        $payload = ['preferences' => $result['preferences']];
        if ($result['ignored'] !== []) {
            $payload['ignored'] = $result['ignored'];
        }

        return ApiResponse::success(
            $payload,
            $result['ignored'] === []
                ? 'Preferences updated successfully'
                : 'Preferences updated successfully; some keys were not recognized'
        );
    }

    public function catalog(): JsonResponse
    {
        return ApiResponse::success(['catalog' => $this->catalogRows()], 'Preference catalog retrieved successfully');
    }

    /**
     * The catalog in wire shape: keys with their type, default and bilingual label.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogRows(): array
    {
        $out = [];
        foreach (PreferenceCatalog::all() as $key => $spec) {
            $out[] = array_merge(['key' => $key], $spec);
        }

        return $out;
    }
}
