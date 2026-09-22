<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleIdTokenRejectedException as Rejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The person behind this token is who Google says they are — or nothing.
 *
 * Google Identity Services hands the browser a signed ID token (a JWT). The
 * browser cannot be trusted with the decision, so this class makes it: the
 * signature is checked against Google's own public keys, and only then are the
 * claims read. A client that says "I verified this with Google" without a
 * signature check is a client claiming what the server has to prove.
 *
 * What is checked, and why each one matters on its own:
 *
 *   * `alg` is exactly `RS256`, from the header, before any key is fetched —
 *     the classic JWT attack is a token whose header says `none` or says HMAC
 *     and is then verified with the public key as the HMAC secret. Pinning the
 *     algorithm removes that whole class.
 *   * the signature verifies over the exact ASCII `header.payload` bytes;
 *   * `iss` is one of Google's two published issuers — `accounts.google.com`
 *     without a scheme is also one of them, so a check written as a single
 *     literal rejects real users;
 *   * `aud` is the client ID of *this* deployment. Without it, a token minted
 *     for any other app in the world would log someone into this one, which is
 *     the entire point of the audience claim;
 *   * `exp` and `iat` bound the token's life in both directions;
 *   * `email_verified` is `true`. Google also issues tokens for addresses it has
 *     not verified, and unverified is not ownership. This is the check that
 *     stops someone signing up a mailbox they do not control;
 *   * `sub` exists and is stable. It is what the identity row stores, because an
 *     email address can be released and handed to a different person years
 *     later, while a subject number cannot.
 *
 * The client ID is a setting (`auth.google_client_id`), not a value in this
 * file: moving the product to another Google Cloud project is an operator
 * decision. When it is missing the answer is an explicit "not configured" —
 * the platform never accepts an unaddressed token, and never pretends the
 * button works.
 *
 * Google's signing keys are cached. That has one consequence worth stating: if
 * Google is unreachable and the cache is empty, sign-ins fail rather than pass
 * unverified. Failing closed is the correct side of that trade for a marketplace
 * holding businesses' legal records.
 */
class GoogleIdTokenVerifier
{
    public function __construct(
        private readonly \App\Services\SystemSettingsService $settings,
    ) {
    }

    /**
     * @return array{subject: string, email: string, name: string|null, picture: string|null, hosted_domain: string|null}
     *
     * @throws Rejected
     */
    public function verify(string $idToken, ?string $expectedNonce = null): array
    {
        $clientId = trim((string) $this->settings->get('auth.google_client_id', ''));

        if ($clientId === '') {
            throw new Rejected(
                Rejected::REASON_NOT_CONFIGURED,
                'This deployment has no Google client ID configured'
            );
        }

        [$header, $payload, $signature] = $this->split($idToken);

        $this->assertSupportedAlgorithm($header);

        $signingInput = $this->asciiSegments($idToken);

        $this->verifySignature($signingInput, $signature, (string) ($header['kid'] ?? ''));

        $this->assertIssuer($payload);
        $this->assertAudience($payload, $clientId);
        $this->assertLifetime($payload);
        $this->assertNonce($payload, $expectedNonce);

        $email = $this->assertVerifiedEmail($payload);

        return [
            'subject' => $this->assertSubject($payload),
            'email' => $email,
            'name' => is_string($payload['name'] ?? null) && $payload['name'] !== '' ? $payload['name'] : null,
            'picture' => is_string($payload['picture'] ?? null) && $payload['picture'] !== '' ? $payload['picture'] : null,
            'hosted_domain' => is_string($payload['hd'] ?? null) && $payload['hd'] !== '' ? $payload['hd'] : null,
        ];
    }

    /**
     * Three dot-separated parts, and nothing else.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function split(string $idToken): array
    {
        $idToken = trim($idToken);

        $parts = explode('.', $idToken);

        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new Rejected(Rejected::REASON_MALFORMED);
        }

        $header = $this->decodeSegment($parts[0]);
        $payload = $this->decodeSegment($parts[1]);

        return [$header, $payload, $this->decodeBase64Url($parts[2])];
    }

    /**
     * @return array{0: string, 1: string} the two segments that were signed, joined by the dot
     */
    private function asciiSegments(string $idToken): string
    {
        $parts = explode('.', trim($idToken));

        return $parts[0] . '.' . $parts[1];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function assertSupportedAlgorithm(array $header): void
    {
        $alg = $header['alg'] ?? null;
        $typ = $header['typ'] ?? null;

        // A `JWT` typ is what Google issues; `at+jwt` and an absent typ are not
        // this token's shape, but the signature check below is what actually
        // protects the platform, so only the algorithm is refused hard.
        if ($alg !== 'RS256' || (is_string($typ) && ! in_array(strtolower($typ), ['jwt', 'at+jwt'], true))) {
            throw new Rejected(Rejected::REASON_BAD_ALGORITHM);
        }

        if (! is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new Rejected(Rejected::REASON_UNKNOWN_KEY);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertIssuer(array $payload): void
    {
        $issuers = (array) config('hassal.auth.google.issuers');

        if (! is_string($payload['iss'] ?? null) || ! in_array($payload['iss'], $issuers, true)) {
            throw new Rejected(Rejected::REASON_BAD_ISSUER);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAudience(array $payload, string $clientId): void
    {
        $aud = $payload['aud'] ?? null;

        $audiences = is_array($aud) ? $aud : (is_string($aud) ? [$aud] : []);

        if (! in_array($clientId, $audiences, true)) {
            throw new Rejected(Rejected::REASON_BAD_AUDIENCE);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertLifetime(array $payload): void
    {
        $futureSkew = max(0, (int) config('hassal.auth.google.max_future_skew_seconds', 300));

        // Clamped, and deliberately: this setting tunes a tolerance, it is not
        // meant to be a way to turn the expiry check off. A deployment that
        // writes `86400` into it gets five minutes of skew allowance and the
        // number in the log if anyone wonders why a stale token worked.
        $leeway = min(300, max(0, (int) config('hassal.auth.google.clock_leeway_seconds', 120)));

        $now = now()->getTimestamp();

        $exp = is_int($payload['exp'] ?? null) ? $payload['exp'] : null;
        $iat = is_int($payload['iat'] ?? null) ? $payload['iat'] : null;

        if ($exp === null || $iat === null) {
            // A token without both bounds is a token whose lifetime is unknown.
            throw new Rejected(Rejected::REASON_MALFORMED);
        }

        // The leeway here answers one thing: two servers whose clocks disagree
        // by half a minute must not lock a real person out of their business.
        // It is not a lifetime extension — Google's ID tokens live an hour, so
        // the widest tolerance this accepts is minutes against a credential that
        // is already on its way out.
        if ($exp + $leeway < $now) {
            throw new Rejected(Rejected::REASON_EXPIRED);
        }

        // Google's own tokens are minted and expire an hour later, so an issue
        // time meaningfully ahead of this clock means the two disagree by more
        // than an operator would ever notice — and a token that claims to come
        // from the future is not evidence about the present.
        if ($iat > $now + $futureSkew) {
            throw new Rejected(Rejected::REASON_NOT_YET_VALID);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNonce(array $payload, ?string $expectedNonce): void
    {
        $claimed = is_string($payload['nonce'] ?? null) ? $payload['nonce'] : null;

        if ($expectedNonce === null || $expectedNonce === '') {
            if ($claimed !== null) {
                // The token promises a nonce and nothing was presented to match
                // it against: that is a token carried from somewhere else.
                throw new Rejected(Rejected::REASON_NONCE_MISMATCH);
            }

            return;
        }

        if ($claimed !== $expectedNonce) {
            throw new Rejected(Rejected::REASON_NONCE_MISMATCH);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertVerifiedEmail(array $payload): string
    {
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';

        $verified = $payload['email_verified'] ?? false;

        // Google sends a real boolean. `is_string` accepts only the one spelling
        // a JSON parser would have left behind, and nothing looser: treating
        // `"false"`, `0` or an absent key as verified would be the whole check
        // written away.
        $isVerified = $verified === true || $verified === 'true';

        if ($email === '' || ! $isVerified) {
            throw new Rejected(Rejected::REASON_EMAIL_UNVERIFIED);
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSubject(array $payload): string
    {
        $subject = is_string($payload['sub'] ?? null) ? trim($payload['sub']) : '';

        // 191 is the width of the column that stores it; a provider that ever
        // handed a longer one would otherwise fail as a 500 inside the database
        // instead of a clear rejection here.
        if ($subject === '' || strlen($subject) > 191) {
            throw new Rejected(Rejected::REASON_MISSING_SUBJECT);
        }

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSegment(string $segment): array
    {
        $json = $this->decodeBase64Url($segment);

        if ($json === '') {
            throw new Rejected(Rejected::REASON_MALFORMED);
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new Rejected(Rejected::REASON_MALFORMED);
        }

        return $decoded;
    }

    private function decodeBase64Url(string $value): string
    {
        $padded = strtr($value, ['-' => '+', '_' => '/']);
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    private function verifySignature(string $signingInput, string $signature, string $kid): void
    {
        if ($signature === '') {
            throw new Rejected(Rejected::REASON_BAD_SIGNATURE);
        }

        $verified = false;

        foreach ($this->keysForKid($kid) as $key) {
            $pem = $this->publicKeyPem($key);

            if ($pem === null) {
                continue;
            }

            // openssl_verify returns 1 on a match, 0 on a mismatch and -1 when
            // the key itself is unusable. Only the first is an acceptance; the
            // other two are treated the same way here because the caller cannot
            // tell them apart, and a platform that logs the difference gains a
            // detail an operator has to interpret anyway.
            if (@openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256) === 1) {
                $verified = true;

                break;
            }
        }

        if (! $verified) {
            throw new Rejected(Rejected::REASON_BAD_SIGNATURE);
        }
    }

    /**
     * Google's signing keys, from the cache when it holds them.
     *
     * @return list<array<string, mixed>>
     */
    private function keysForKid(string $kid): array
    {
        $cached = Cache::get($this->jwksCacheKey());

        $keys = is_array($cached) ? $cached : $this->fetchKeys();

        return array_values(array_filter(
            $keys,
            static fn (array $key): bool => ($key['kid'] ?? null) === $kid
                && ($key['kty'] ?? null) === 'RSA'
                && (($key['alg'] ?? null) === null || $key['alg'] === 'RS256')
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchKeys(): array
    {
        $url = (string) config('hassal.auth.google.jwks_url');

        if ($url === '') {
            throw new Rejected(Rejected::REASON_PROVIDER_UNAVAILABLE);
        }

        $response = null;

        try {
            $response = Http::timeout(max(1, (int) config('hassal.auth.google.http_timeout_seconds', 8)))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url);
        } catch (\Throwable) {
            // The message of a transport failure describes this server's network,
            // not the caller's request, so it is logged rather than returned.
            logger()->warning('Google signing keys could not be fetched', ['url' => $url]);

            throw new Rejected(Rejected::REASON_PROVIDER_UNAVAILABLE);
        }

        if (! $response->successful()) {
            logger()->warning('Google signing keys endpoint refused', ['status' => $response->status()]);

            throw new Rejected(Rejected::REASON_PROVIDER_UNAVAILABLE);
        }

        $keys = $response->json('keys');

        if (! is_array($keys) || $keys === []) {
            logger()->warning('Google signing keys endpoint returned no keys');

            throw new Rejected(Rejected::REASON_PROVIDER_UNAVAILABLE);
        }

        // Only RSA entries that carry a kid and a modulus are worth keeping: a
        // malformed entry must not be able to shadow a good one.
        $usable = array_values(array_filter(
            $keys,
            static fn ($key): bool => is_array($key)
                && ($key['kty'] ?? null) === 'RSA'
                && is_string($key['kid'] ?? null) && $key['kid'] !== ''
                && is_string($key['n'] ?? null) && $key['n'] !== ''
        ));

        if ($usable === []) {
            throw new Rejected(Rejected::REASON_PROVIDER_UNAVAILABLE);
        }

        Cache::put($this->jwksCacheKey(), $usable, max(60, (int) config('hassal.auth.google.jwks_cache_seconds', 21600)));

        return $usable;
    }

    private function jwksCacheKey(): string
    {
        // Keyed by the URL so a deployment that points at a different issuer's
        // certificate endpoint — an air-gapped mirror, for instance — cannot
        // read another deployment's cached keys out of a shared cache store.
        return 'hassal_google_jwks_' . md5((string) config('hassal.auth.google.jwks_url'));
    }

    /**
     * A JWK (its `n` and `e` in base64url) turned into a PEM public key.
     *
     * PHP's openssl functions take a PEM, and Google publishes modulus and
     * exponent, so the DER structure in between is built here:
     *
     *     SubjectPublicKeyInfo ::= SEQUENCE { AlgorithmIdentifier, BIT STRING(RSAPublicKey) }
     *     RSAPublicKey        ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
     *
     * @param  array<string, mixed>  $key
     */
    private function publicKeyPem(array $key): ?string
    {
        $modulus = $this->decodeBase64Url((string) ($key['n'] ?? ''));
        $exponent = $this->decodeBase64Url((string) ($key['e'] ?? ''));

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        $rsaPublicKey = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));

        // rsaEncryption (1.2.840.113549.1.1.1) with NULL parameters.
        $algorithmIdentifier = $this->derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");

        // The BIT STRING's content length counts the leading "no unused bits"
        // byte as well, which is why the +1 sits on the length rather than on
        // the encoded value.
        $bitString = "\x03" . $this->derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $subjectPublicKeyInfo = $this->derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . '-----END PUBLIC KEY-----';
    }

    private function derSequence(string $contents): string
    {
        return "\x30" . $this->derLength($contents) . $contents;
    }

    private function derInteger(string $magnitude): string
    {
        // An INTEGER is signed in DER, so a leading zero byte is added when the
        // top bit is set — otherwise a 256-byte modulus reads as negative and
        // openssl rejects the key.
        $magnitude = ltrim($magnitude, "\x00");

        if ($magnitude === '') {
            $magnitude = "\x00";
        }

        if (ord($magnitude[0]) > 0x7f) {
            $magnitude = "\x00" . $magnitude;
        }

        return "\x02" . $this->derLength($magnitude) . $magnitude;
    }

    private function derLength(string|int $content): string
    {
        $length = is_int($content) ? $content : strlen($content);

        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        $remaining = $length;

        while ($remaining > 0) {
            $bytes = chr($remaining % 256) . $bytes;
            $remaining = intdiv($remaining, 256);
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
