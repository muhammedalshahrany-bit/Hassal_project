<?php

namespace App\Core\Rbac;

/**
 * Which *subject* a permission name may be attached to.
 *
 * `config/rbac.php` declares the catalog and this class reads the scope off it:
 * `platform` (an act upon the market or upon somebody else's business),
 * `business` (a company acting on its own trade, through one membership row),
 * `both` (a name that legitimately reads on either side, where the organization
 * context — not the name — decides what it reaches).
 *
 * The point of putting the boundary in code is that prose in a config comment
 * cannot refuse a write. Two doors exist on purpose:
 *
 *   * `unacceptableOnAccount()` — a business capability stamped onto the account
 *     follows the person into every company they ever join and survives their
 *     leaving it, which is the fault the retired `SellerAdmin`/`BuyerAdmin` roles
 *     were emptied for. Refusing it here means no screen, script or seed can
 *     reintroduce it by accident.
 *   * `unacceptableInOrganization()` — a platform capability inside a company's
 *     role matrix puts a counterparty in charge of the neutral decisions: whether
 *     a rival's listing is approved, whether a business may trade at all, how a
 *     dispute between two firms is ruled. No combination of company standings may
 *     produce one.
 *
 * `SuperAdmin` is the one holder the scope filter does not speak for, and not as
 * an exemption written in code: it is declared `'*'` in the config, the seeder
 * gives it the entire catalog, and the isolation rules below apply to grants an
 * administrator *makes*. Nothing here lets a screen attach `offers.moderate` to
 * an organization's owner standing — the platform owner holding everything is a
 * different statement from the platform letting a company hold one thing.
 *
 * These methods answer with the offending names rather than throwing, because
 * the refusal has to reach the caller as a list it can show: a 422 that says
 * which three names were wrong and why is an admin screen that can be corrected,
 * and a thrown exception is one that cannot.
 */
class RbacScope
{
    public const PLATFORM = 'platform';
    public const BUSINESS = 'business';
    public const BOTH = 'both';

    /**
     * The scope of one name, as the catalog declares it.
     *
     * A name the catalog never declared answers `business`, which is the
     * restrictive side: it may be granted inside a company that already names it
     * in a standing, and it may never be attached to an account. Callers that
     * care whether the name exists at all validate against
     * `config('rbac.permissions')` first; this method deliberately does not
     * invent a fourth scope for a typo.
     */
    public static function scopeOf(string $permission): string
    {
        /** @var array<string, list<string>> $scopes */
        $scopes = (array) config('rbac.permission_scopes');

        if (in_array($permission, $scopes[self::PLATFORM] ?? [], true)) {
            return self::PLATFORM;
        }

        if (in_array($permission, $scopes[self::BOTH] ?? [], true)) {
            return self::BOTH;
        }

        return self::BUSINESS;
    }

    /** @return list<string> every catalogued name carrying the given scope */
    public static function namesFor(string $scope): array
    {
        return array_values(array_filter(
            (array) config('rbac.permissions'),
            static fn (string $name): bool => self::scopeOf($name) === $scope
        ));
    }

    /**
     * Every catalogued name a company may hold — `business` and `both` together.
     *
     * The set the organization door offers, and the set the platform's own names
     * must be absent from. Asked as a question about scopes rather than as a
     * hardcoded list so the two doors cannot drift apart on which names are on
     * offer: `unacceptableInOrganization()` refuses exactly what this accepts.
     *
     * @return list<string>
     */
    public static function namesReachableInsideOrganization(): array
    {
        return array_values(array_filter(
            (array) config('rbac.permissions'),
            static fn (string $name): bool => self::exercisableInsideOrganization($name)
        ));
    }

    /**
     * Names in the list that must not be held by an account.
     *
     * @param  array<array-key, string>  $permissions
     * @return list<string>
     */
    public static function unacceptableOnAccount(array $permissions): array
    {
        return array_values(array_unique(array_filter(
            $permissions,
            static fn (string $name): bool => self::scopeOf($name) === self::BUSINESS
        )));
    }

    /**
     * Names in the list that must not be reachable from inside a company.
     *
     * @param  array<array-key, string>  $permissions
     * @return list<string>
     */
    public static function unacceptableInOrganization(array $permissions): array
    {
        return array_values(array_unique(array_filter(
            $permissions,
            static fn (string $name): bool => self::scopeOf($name) === self::PLATFORM
        )));
    }

    /**
     * May a permission carrying this scope be exercised from inside a company?
     *
     * Used by the resolution path, where the question is not "may this be
     * granted" (the two methods above guard that, at write time) but whether an
     * organization-side row is allowed to answer for a name at all. A
     * `platform`-scoped name never is, whatever rows a bad migration left
     * behind: the write doors refuse to create them, and this refuses to read
     * them even if something else did.
     */
    public static function exercisableInsideOrganization(string $permission): bool
    {
        return self::scopeOf($permission) !== self::PLATFORM;
    }

    /**
     * The names a signed-in person may exercise with no organization at all.
     *
     * A consumer (§1 of the product brief) is not a degraded company: pressing
     * "interested" on a listing or asking a public question is a statement by a
     * named human, and every row it writes still names that human — by account
     * id, not by a commercial registration. These names stay in the `business`
     * scope because the company door may grant, revoke and audit them for
     * members acting *as* a company; this list only answers the other question,
     * the one no scope answers: may the person act when no company stands
     * behind them. The list is short on purpose — liking and asking, never
     * trading — because the moment a person without a business record may
     * create a purchase request is the moment the counterparty's trust in the
     * named company behind an order means nothing.
     *
     * @return list<string>
     */
    public static function personCapabilities(): array
    {
        return array_values((array) config('rbac.person_capabilities', []));
    }

    /**
     * Is this name one the account may exercise as a person, with no tenant in
     * context? The scope rule still binds: a platform name is never a person
     * capability whatever the list says, so an edit here cannot open a
     * moderation door to a stranger with a phone number.
     */
    public static function isPersonCapability(string $permission): bool
    {
        return self::exercisableInsideOrganization($permission)
            && in_array($permission, self::personCapabilities(), true);
    }

    /**
     * May a permission carrying this scope answer for the account itself?
     *
     * The mirror of the above, and the reason leaving a company takes the access
     * with it: a `business`-scoped name is never the account's, so a
     * `model_has_permissions` row naming one grants nothing, whether it was
     * written by a screen, a seeder or a database console.
     */
    public static function exercisableByAccount(string $permission): bool
    {
        return self::scopeOf($permission) !== self::BUSINESS;
    }

    /**
     * The catalog with its scope beside each name, for a screen that has to show
     * the boundary rather than hit it.
     *
     * @return list<array{name: string, scope: string}>
     */
    public static function catalog(): array
    {
        return array_map(
            static fn (string $name): array => ['name' => $name, 'scope' => self::scopeOf($name)],
            (array) config('rbac.permissions')
        );
    }
}
