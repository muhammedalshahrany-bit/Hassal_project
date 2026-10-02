<?php

namespace Tests\Unit;

use App\Platform\Support\OutboundEndpointPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A webhook endpoint is a POST this server makes on behalf of a tenant, from
 * wherever the server sits in the network. These tests are the reason that is
 * not a way to reach the cloud metadata service, the database on 127.0.0.1, or
 * a colleague's admin panel — and they assert the *refusal*, not the response,
 * because the whole point is that no request is ever made.
 *
 * The public-host case avoids naming a real domain: an unresolvable host is
 * treated as "not proven private" and accepted, which is what happens on a CI
 * runner with no DNS. That is the same answer a receiver would get, so the
 * test does not quietly depend on the network.
 */
class OutboundEndpointPolicyTest extends TestCase
{
    public function test_a_public_https_endpoint_is_accepted(): void
    {
        $this->assertTrue(
            OutboundEndpointPolicy::accepts('https://hooks.partners.example/webhooks/hassal'),
            'A public HTTPS endpoint should be deliverable.'
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function internalEndpointProvider(): array
    {
        return [
            'cloud metadata service' => ['https://169.254.169.254/latest/meta-data/', 'metadata endpoint'],
            'loopback v4' => ['https://127.0.0.1/hook', 'loopback'],
            'loopback v6' => ['https://[::1]/hook', 'loopback'],
            'class A private' => ['https://10.20.30.40/hook', 'private'],
            'class B private' => ['https://172.16.5.5/hook', 'private'],
            'class C private' => ['https://192.168.1.10/hook', 'private'],
            'carrier-grade nat' => ['https://100.64.0.1/hook', 'CGNAT'],
            'unique local v6' => ['https://[fc00::12]/hook', 'private'],
            'unspecified' => ['https://0.0.0.0/hook', 'internal'],
        ];
    }

    #[DataProvider('internalEndpointProvider')]
    public function test_endpoints_that_point_inside_the_network_are_refused(string $url, string $_label): void
    {
        $violations = OutboundEndpointPolicy::violations($url);

        $this->assertNotSame([], $violations, "Blocked nothing for [{$url}].");
        $this->assertMatchesRegularExpression(
            '/private|internal|loopback|fully-qualified/i',
            implode(' ', $violations),
            "Expected an address-related refusal for [{$url}], got: {$violations[0]}"
        );
    }

    public function test_plain_http_is_refused_outside_development(): void
    {
        $violations = OutboundEndpointPolicy::violations('http://hooks.partners.example/webhook');

        $this->assertNotSame([], $violations);
        $this->assertStringContainsStringIgnoringCase('https', $violations[0]);
    }

    public function test_bare_and_internal_hostnames_are_refused(): void
    {
        foreach (['https://localhost/hook', 'https://internal-svc/hook', 'https://redis.service.local/hook'] as $url) {
            $this->assertNotSame(
                [],
                OutboundEndpointPolicy::violations($url),
                "Accepted a non-qualified or internal hostname: [{$url}]."
            );
        }
    }

    public function test_integer_host_forms_are_refused_even_though_curl_would_resolve_them(): void
    {
        // 2130706433 is 127.0.0.1 written as a decimal integer; it is not a
        // valid IP literal, so a naive filter_var() check waves it through and
        // the HTTP client connects to loopback anyway.
        $this->assertNotSame([], OutboundEndpointPolicy::violations('https://2130706433/hook'));
        $this->assertNotSame([], OutboundEndpointPolicy::violations('https://0x7f000001/hook'));
    }

    public function test_credentials_embedded_in_the_url_are_refused(): void
    {
        $violations = OutboundEndpointPolicy::violations('https://admin:hunter2@hooks.partners.example/webhook');

        $this->assertNotSame([], $violations);
        $this->assertStringContainsStringIgnoringCase('credential', $violations[0]);
    }

    public function test_non_http_schemes_are_refused(): void
    {
        foreach (['ftp://hooks.partners.example/webhook', 'file:///etc/passwd', 'gopher://10.0.0.9:11211/'] as $url) {
            $this->assertNotSame([], OutboundEndpointPolicy::violations($url), "Accepted [{$url}].");
        }
    }

    public function test_malformed_and_empty_endpoints_are_refused(): void
    {
        $this->assertNotSame([], OutboundEndpointPolicy::violations(''));
        $this->assertNotSame([], OutboundEndpointPolicy::violations('not a url at all'));
        $this->assertNotSame([], OutboundEndpointPolicy::violations(null));
    }

    public function test_a_development_deployment_may_opt_into_internal_endpoints(): void
    {
        config()->set('hassal.webhooks.allow_private_endpoints', true);

        $this->assertTrue(
            OutboundEndpointPolicy::accepts('http://127.0.0.1:8000/webhook/dev-receiver'),
            'The opt-in exists so a developer can see a delivery on their own machine.'
        );

        // Even then the scheme and credential rules hold: the escape hatch is
        // about addresses, not about accepting anything parse_url() returns.
        $this->assertFalse(OutboundEndpointPolicy::accepts('ftp://127.0.0.1/webhook'));
        $this->assertFalse(OutboundEndpointPolicy::accepts('https://u:p@127.0.0.1/webhook'));
    }
}
