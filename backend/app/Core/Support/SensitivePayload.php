<?php

namespace App\Core\Support;

use Illuminate\Http\UploadedFile;

/**
 * What an audit row may keep about a request body.
 *
 * An audit log is written to be read later by someone who did not witness the
 * event — which is exactly why it cannot hold whatever a request happened to
 * carry. Before this existed the redaction was `$request->except([...])` with
 * four literal names, and `except()` matches top-level keys only. That left
 * three whole classes of secret in the table: a nested `password` inside a
 * profile payload, a `client_secret` under any other spelling, and the bearer
 * token in the body of the one endpoint that echoes it back. `old_values` and
 * `new_values` are plain JSON columns, so a leaked trail is a second credential
 * store with looser access controls than the first.
 *
 * Two rules make this auditable rather than clever:
 *   1. A redacted value leaves the *key* in place. Dropping it would make a
 *      later reader unable to tell "this request carried a password" from "this
 *      request carried nothing" — and the first is the fact an investigation
 *      needs.
 *   2. Identification numbers are masked rather than removed. A row that says
 *      `national_id: ***` cannot answer "was this the account with that ID?",
 *      while a row that keeps the last four characters can, and is not a copy of
 *      the document.
 *
 * Adding a key here is cheap; adding one to a per-controller list is how the
 * four-name `except()` array came to be the only line of defence.
 */
final class SensitivePayload
{
    /** Shown in place of a value this log will not carry. */
    public const REDACTED = '***';

    /**
     * Keys whose value is never recorded, whatever it looks like.
     *
     * Matched case- and separator-insensitively, so `passwordConfirmation`,
     * `password_confirmation` and `PASSWORD` are the same entry.
     */
    private const OPAQUE = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'old_password',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'bearer_token',
        'client_secret',
        'api_key',
        'apikey',
        'secret',
        'signature',
        'signing_key',
        'webhook_secret',
        'authorization',
        'private_key',
        'otp',
        'one_time_password',
        'verification_code',
        'reset_token',
    ];

    /**
     * Substrings that make a key opaque even inside a longer name.
     *
     * `google_client_secret`, `webhook_signing_secret`, `storage_access_secret`
     * — an exact-match list would have to predict every compound a controller
     * invents, and the cost of missing one is a credential in a JSON column.
     */
    private const OPAQUE_NEEDLES = [
        'password',
        'secret',
        'token',
        'private_key',
        'api_key',
        'apikey',
        'authorization',
        'signature',
    ];

    /**
     * Keys kept, but only by their tail.
     *
     * These are identity attributes rather than credentials: an investigator
     * needs to correlate a row to an account, and the last few characters do
     * that without the log becoming a copy of the civil register.
     */
    private const MASKED = [
        'national_id',
        'cr_number',
        'commercial_registration_number',
        'vat_number',
        'iban',
        'card_number',
        'account_number',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            $name = (string) $key;

            $out[$key] = match (true) {
                // A file is described, not inlined: the trail's job is to record
                // that a named person attached a named document, and copying the
                // bytes into JSON would put a signed certificate inside a table
                // that a reporting query can read.
                $value instanceof UploadedFile => self::describeFile($value),
                $value instanceof \BackedEnum => self::valueFor($name, (string) $value->value),
                is_array($value) && self::isOpaque($name) => self::REDACTED,
                is_array($value) => self::redact($value),
                self::isOpaque($name) => self::REDACTED,
                default => self::valueFor($name, $value),
            };
        }

        return $out;
    }

    /**
     * The whole request, files included, ready for a JSON column.
     *
     * @return array<array-key, mixed>
     */
    public static function fromRequest(\Illuminate\Http\Request $request): array
    {
        $payload = $request->all();

        // Headers are in scope because one of them is a credential: a client
        // that authenticates with `Authorization: Bearer …` would otherwise have
        // its live token written to the trail by any code path that dumps the
        // request rather than its body. Only the presence is recorded.
        if ($request->headers->has('Authorization')) {
            $payload['authorization'] = self::REDACTED;
        }

        if ($request->headers->has('X-Idempotency-Key')) {
            // Not a secret, but not a fact either: it is a random uuid whose only
            // use is replay detection, and keeping it invites a reader to treat
            // it as a correlation id when the envelope's own is the reliable one.
            $payload['idempotency_key'] = self::REDACTED;
        }

        return self::redact($payload);
    }

    public static function isOpaque(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        if (in_array($normalized, self::OPAQUE, true)) {
            return true;
        }

        foreach (self::OPAQUE_NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function valueFor(string $key, mixed $value): mixed
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $value;
        }

        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        if (in_array($normalized, self::MASKED, true)) {
            return self::maskTail((string) $value);
        }

        // A free-text field is where a person types the thing they were not
        // asked for. A reason box that reads "my password is hunter2" is in the
        // log either way, so the least this can do is not make it searchable —
        // the literal word is what an operator greps for when auditing a leak.
        if (is_string($value) && preg_match('/\b(password|passphrase|رمز\s*المرور|كلمة\s*السر)\b/i', $value) === 1) {
            return self::REDACTED . ' (text matched a credential pattern)';
        }

        return $value;
    }

    /**
     * Keep the tail, replace the head.
     *
     * Four characters is the compromise: enough to distinguish two accounts,
     * not enough to reconstruct a national ID or an IBAN that someone has typed
     * in full elsewhere.
     */
    private static function maskTail(string $value): string
    {
        $length = strlen($value);

        if ($length <= 4) {
            return self::REDACTED;
        }

        return str_repeat('…', min(6, $length - 4)) . substr($value, -4);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeFile(UploadedFile $file): array
    {
        return array_filter([
            'file' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ], static fn ($v) => $v !== null);
    }
}
