<?php

namespace App\Support;

/**
 * Can this platform safely POST to a URL somebody typed into a form?
 *
 * A webhook endpoint is a server-side request the platform makes with its own
 * network position. Without a policy, any organization that can register an
 * endpoint can make this application issue authenticated-looking POSTs to
 * `http://169.254.169.254/latest/meta-data/`, to a database admin port on
 * `127.0.0.1`, or to a service on the private network the app is deployed
 * behind — and the delivery log then leaks part of the response back into the
 * tenant's own console. That is a server-side request forgery primitive
 * offered as a product feature.
 *
 * What this checks: the URL's shape (https, no credentials, no unusual host
 * syntax), and the addresses the host resolves to (never loopback, private,
 * link-local, multicast or reserved).
 *
 * What it cannot check: a host that answers publicly when registered and
 * privately when delivered (DNS rebinding). Delivery re-runs the same checks
 * and redirects are disabled, which removes the easy version of that attack;
 * closing the remaining window means pinning the resolved address at the
 * transport layer or restricting egress at the network layer, and an operator
 * who needs that guarantee should do it on the infrastructure, not here.
 */
final class OutboundEndpointPolicy
{
    /**
     * @return array<int, string> human-readable violations; empty means acceptable
     */
    public static function violations(?string $url): array
    {
        if (!is_string($url) || trim($url) === '') {
            return ['The webhook endpoint must be an absolute URL.'];
        }

        $parts = parse_url(trim($url));

        if ($parts === false || empty($parts['host'])) {
            return ['The webhook endpoint could not be parsed as a URL.'];
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['https', 'http'], true)) {
            return ['The webhook endpoint must use http or https.'];
        }

        // `https://internal-host@attacker.example/` parses as attacker.example
        // in most libraries and as something else in others. No legitimate
        // receiver needs credentials in a URL we are going to POST to.
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return ['The webhook endpoint must not contain username or password credentials.'];
        }

        if ($scheme === 'http' && !self::allowPrivateEndpoints()) {
            return ['The webhook endpoint must use https.'];
        }

        // The escape hatch is a deployment decision for a developer's own
        // machine, where the receiver is http://localhost:8000 and there is no
        // internal network worth forging a request into. A public deployment
        // leaves it off and gets the full check.
        if (self::allowPrivateEndpoints()) {
            return [];
        }

        $host = self::normalizeHost($parts['host']);
        $nameViolations = self::hostNameViolations($host);

        if ($nameViolations !== []) {
            return $nameViolations;
        }

        $addresses = self::isIpLiteral($host)
            ? [$host]
            : self::resolve($host);

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return [self::privateAddressMessage($host)];
            }
        }

        return [];
    }

    /**
     * Single-argument form used by callers that would rather throw than branch.
     */
    public static function accepts(?string $url): bool
    {
        return self::violations($url) === [];
    }

    public static function allowPrivateEndpoints(): bool
    {
        return (bool) config('hassal.webhooks.allow_private_endpoints', false);
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        // IDN hosts arrive percent/ punycode-encoded from parse_url in practice;
        // strip an IPv6 pair of brackets so the literal check sees the address.
        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }

    /**
     * @return array<int, string>
     */
    private static function hostNameViolations(string $host): array
    {
        if (self::isIpLiteral($host)) {
            return [];
        }

        // Without this, `http://2130706433/` passes filter_var() as "not an IP"
        // and curl happily connects to 127.0.0.1.
        if (preg_match('/^\d+$/', $host) === 1) {
            return ['The webhook endpoint host is not a valid hostname.'];
        }

        // Fully-qualified hostnames only. Bare names such as `localhost`,
        // `metadata` or an internal NetBIOS/`*.svc` short name are exactly what
        // this request would be forged toward.
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            return ['The webhook endpoint must be a fully-qualified public hostname or a public IP address.'];
        }

        foreach (['.localhost', '.internal', '.local', '.home', '.lan', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return ['The webhook endpoint must not point at a private network hostname.'];
            }
        }

        return [];
    }

    private static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        $addresses = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = array_merge($addresses, $v4);
        }

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        // An unresolvable host is not proven private, and refusing it here
        // would make the platform depend on DNS being reachable from the web
        // tier. Delivery fails on its own if the name never resolves.
        return $addresses;
    }

    private static function isPublicAddress(string $address): bool
    {
        if (self::isCgnat($address)) {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * 100.64.0.0/10 is carrier-grade NAT (and the range cloud metadata
     * services occasionally sit behind); PHP's reserved-range flag set does not
     * cover every build the same way, so it is checked explicitly.
     */
    private static function isCgnat(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($address);

        return $long !== false && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255');
    }

    private static function privateAddressMessage(string $host): string
    {
        return "The webhook endpoint \"{$host}\" resolves to a private or internal address, which this platform will not post to.";
    }
}
