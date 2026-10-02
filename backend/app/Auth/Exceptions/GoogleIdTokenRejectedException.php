<?php

namespace App\Auth\Exceptions;

use Exception;

/**
 * A Google assertion that will not be turned into a login.
 *
 * `reason` is a machine code, never a free-text sentence, because the code is
 * what the client branches on and what the log line carries. It is also
 * deliberately narrow: "the signature does not match" and "this platform has
 * no client ID configured" give the person trying to sign in the same answer —
 * they can fix neither — but a different one for whoever reads the log, and an
 * operator debugging a broken install needs to tell a misconfiguration apart
 * from an attack attempt.
 *
 * Nothing here names an email address. A rejected login is the one place where
 * echoing an address back turns the endpoint into an account enumerator, so
 * `retryable` and `reason` are the whole interface.
 */
class GoogleIdTokenRejectedException extends Exception
{
    /** The token failed a check that says something about the token itself. */
    public const REASON_MALFORMED = 'GOOGLE_TOKEN_MALFORMED';
    public const REASON_BAD_ALGORITHM = 'GOOGLE_TOKEN_ALG_UNSUPPORTED';
    public const REASON_UNKNOWN_KEY = 'GOOGLE_TOKEN_KEY_UNAVAILABLE';
    public const REASON_BAD_SIGNATURE = 'GOOGLE_TOKEN_SIGNATURE_INVALID';
    public const REASON_BAD_ISSUER = 'GOOGLE_TOKEN_ISSUER_REJECTED';
    public const REASON_BAD_AUDIENCE = 'GOOGLE_TOKEN_AUDIENCE_REJECTED';
    public const REASON_EXPIRED = 'GOOGLE_TOKEN_EXPIRED';
    public const REASON_NOT_YET_VALID = 'GOOGLE_TOKEN_NOT_YET_VALID';
    public const REASON_EMAIL_UNVERIFIED = 'GOOGLE_EMAIL_UNVERIFIED';
    public const REASON_MISSING_SUBJECT = 'GOOGLE_TOKEN_SUBJECT_MISSING';
    public const REASON_NONCE_MISMATCH = 'GOOGLE_TOKEN_NONCE_MISMATCH';

    /** Nothing about the token was checked: this platform is not configured. */
    public const REASON_NOT_CONFIGURED = 'GOOGLE_SIGNIN_NOT_CONFIGURED';

    /** Google could not be reached and no cached signing key was usable. */
    public const REASON_PROVIDER_UNAVAILABLE = 'GOOGLE_PROVIDER_UNAVAILABLE';

    private string $reason;

    public function __construct(string $reason, string $message = 'Google sign-in could not be verified')
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Whether signing in again could possibly work.
     *
     * A token that has expired or that Google's keys cannot yet be checked
     * against is worth another click. A signature that does not verify is not,
     * and telling the person to retry would be telling them to attack the same
     * door until it opens.
     */
    public function retryable(): bool
    {
        return in_array($this->reason, [
            self::REASON_EXPIRED,
            self::REASON_NOT_YET_VALID,
            self::REASON_UNKNOWN_KEY,
            self::REASON_PROVIDER_UNAVAILABLE,
        ], true);
    }
}
