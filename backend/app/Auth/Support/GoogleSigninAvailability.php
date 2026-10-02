<?php

namespace App\Auth\Support;

use App\Platform\Services\SystemSettingsService;

/**
 * Whether this deployment may offer Google at all, and what it takes to say so.
 *
 * Two questions with two different fixes, which is the whole reason they are
 * separate methods rather than one boolean: an operator who sees "sign-in is off"
 * and has already turned the switch on goes looking for a switch that is already
 * on. The door is shut by the setting, and it is shut by the setting being on with
 * nothing pasted behind it, and those are different sentences to a person reading
 * a console.
 *
 * This lives beside the settings rather than in a controller because it is a
 * fact about the deployment, not about a request. Three places have to answer it
 * about the same install: the sign-in endpoint before it reads a token, the
 * cold-start payload that decides whether to draw the button, and the verifier
 * that needs the client ID to check a token's audience against. A rule that
 * lives in one of them is a rule the other two quietly ignore — and the
 * disagreement is invisible until a person sees a button that refuses them.
 */
final class GoogleSigninAvailability
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    /**
     * Has the platform opened the Google door?
     */
    public function enabled(): bool
    {
        return filter_var(
            (string) $this->settings->get('auth.google_enabled', '0'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Has this deployment told Google who it is?
     *
     * A switch with no client ID behind it is not half-on. The button cannot be
     * drawn without the ID and no token could be addressed to it, so the door
     * stays shut from both ends. The value itself is a public setting — Google's
     * own documentation calls a client ID a public identifier, and the browser
     * has to hold it to ask for a token — which is why it lives in
     * `system_settings` where an administrator can change it rather than in a
     * file that would need a deploy, and why no client *secret* exists here at
     * all: an ID token from the browser is verified against Google's published
     * keys, not against a credential.
     */
    public function clientConfigured(): bool
    {
        return $this->clientId() !== null;
    }

    /**
     * The client ID a browser needs to ask Google for a token, or null.
     *
     * One read of the setting, in one normalised form: trimmed, and an empty
     * string answered as null rather than as a value that looks usable but is
     * not. Both questions above and below are asked of this, so a blank that was
     * pasted with spaces around it cannot be "configured" to one reader and
     * "missing" to another.
     */
    public function clientId(): ?string
    {
        $clientId = trim((string) $this->settings->get('auth.google_client_id', ''));

        return $clientId === '' ? null : $clientId;
    }
}
