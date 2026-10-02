<?php

namespace App\Core\Support;

use Illuminate\Http\UploadedFile;

/**
 * What a submitted file actually is, read from its own bytes.
 *
 * Laravel's `UploadedFile::getMimeType()` already runs `finfo` over the temp
 * file rather than trusting the `Content-Type` header or the filename, and the
 * upload paths here have used it from the start — so the naive version of "the
 * browser said it was a JPEG" was never the exposure. What that leaves, and what
 * this class answers, is the two cases a content detector can be wrong about:
 *
 *   1. **A disagreement between detectors.** `finfo` samples a window of the
 *      file; a leading bytes check reads the first sixteen. A document whose
 *      magic number says `%PDF-` and whose `finfo` answer says `image/png` is
 *      not an image with an odd name, it is a crafted file, and it is refused.
 *      Polyglot PNG/PDF and GIF/JS files are built precisely to survive a
 *      single-source check.
 *   2. **A type that has no business being stored.** SVG is `image/svg+xml` to
 *      every detector and is an XML document that runs script when opened. The
 *      accepted list is what keeps it out; this class keeps the *detected* type
 *      as the thing compared against that list, so a `.jpg` containing an SVG
 *      never reaches a viewer.
 *
 * The rule that makes this worth having: **the extension on disk and the
 * `Content-Type` on download both come out of here**, never out of the
 * client's filename. Two endpoints that each wrote their own `match ($mime)`
 * table is how a stored name and a served header could disagree — and a
 * disagreement in the server's favour is the only thing `nosniff` cannot fix.
 */
final class UploadProbe
{
    /**
     * Leading bytes per accepted type.
     *
     * Deliberately only the types `config/hassal.php` lists as uploadable. An
     * unlisted type is refused, not guessed at, so this table cannot become the
     * place a new format is quietly admitted.
     */
    private const SIGNATURES = [
        'image/jpeg' => [
            ["\xFF\xD8\xFF"],
        ],
        'image/png' => [
            ["\x89PNG\r\n\x1a\n"],
        ],
        'image/webp' => [
            // `RIFF` at 0, `WEBP` at 8 — both halves, because a file that is
            // only `RIFF`-headed is a WAV or an AVI.
            ["RIFF", 'WEBP', 8],
        ],
        'application/pdf' => [
            ["%PDF-"],
        ],
    ];

    /**
     * A detected type that is always refused, whatever the accepted list says.
     *
     * SVG is the case that looks harmless and is not: it renders as an image
     * everywhere a product photograph appears, and it carries script. Nothing
     * in this product needs a vector upload — a certificate is a PDF or a scan.
     */
    private const NEVER_ACCEPTED = [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'text/plain',
        'application/x-httpd-php',
        'application/x-sh',
        'application/x-shellscript',
        'text/x-python',
        'application/x-executable',
        'application/x-dosexec',
    ];

    /**
     * @return array{ok: bool, mime: string, extension: string, reason: ?string}
     */
    public static function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        // `finfo` reads the file at that path, so no path means no detection —
        // and a detection that did not happen must refuse, not fall back to the
        // name the sender chose.
        if ($path === false || $path === null || ! is_readable($path)) {
            return self::refused('unknown', 'unreadable');
        }

        $detected = $file->getMimeType() ?: 'unknown';

        if (in_array($detected, self::NEVER_ACCEPTED, true)) {
            return self::refused($detected, 'never_accepted');
        }

        $head = self::head($path);

        // The two detectors must name the same type. This is the check a single
        // `getMimeType()` call cannot make, and the whole reason for the class:
        // a file whose first bytes are a PDF header and whose sampled type is
        // `image/png` is a construction, not a photograph.
        if (! self::magicAgrees($detected, $head)) {
            return self::refused($detected, 'signature_mismatch');
        }

        $extension = self::extensionFor($detected);

        if ($extension === null) {
            return self::refused($detected, 'no_extension');
        }

        return ['ok' => true, 'mime' => $detected, 'extension' => $extension, 'reason' => null];
    }

    /**
     * Is this file one of the types the deployment accepts?
     *
     * Compared against the probe's answer, not against `$file->getClientMimeType()`
     * — the client's own claim is the value an attacker controls.
     *
     * @param  list<string>  $accepted
     */
    public static function isAcceptable(UploadedFile $file, array $accepted): array
    {
        $probe = self::inspect($file);

        if (! $probe['ok']) {
            return $probe;
        }

        if (! in_array($probe['mime'], $accepted, true)) {
            return self::refused($probe['mime'], 'unsupported_type');
        }

        return $probe;
    }

    /**
     * A refusal carrying the detected type, so a caller can name what arrived
     * instead of saying only "no".
     *
     * @return array{ok: bool, mime: string, extension: string, reason: string}
     */
    private static function refused(string $mime, string $reason): array
    {
        return ['ok' => false, 'mime' => $mime, 'extension' => '', 'reason' => $reason];
    }

    /**
     * The first bytes of the file, as many as any signature needs.
     */
    private static function head(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $bytes = fread($handle, 16);
        fclose($handle);

        return $bytes === false ? '' : $bytes;
    }

    private static function magicAgrees(string $mime, string $head): bool
    {
        $signatures = self::SIGNATURES[$mime] ?? null;

        // A detected type with no signature here is not "fine"; it is a type
        // this platform has not decided what to do with, and the safe reading of
        // an undecided case on an upload path is a refusal. The accepted lists in
        // `config/hassal.php` contain only signed types, so this branch rejects
        // only something that was never going to be stored.
        if ($signatures === null) {
            return false;
        }

        foreach ($signatures as $signature) {
            if (count($signature) === 3) {
                [$first, $second, $offset] = $signature;

                if (str_starts_with($head, $first) && substr($head, (int) $offset, strlen($second)) === $second) {
                    return true;
                }

                continue;
            }

            if (str_starts_with($head, $signature[0])) {
                return true;
            }
        }

        return false;
    }

    private static function extensionFor(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => null,
        };
    }
}
