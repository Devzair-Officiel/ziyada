<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Seo\IndexabilityPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class IndexabilityPolicyTest extends TestCase
{
    // ---- Matrice de directives robots ----

    public function testSiteIndexableFalseAlwaysReturnsNoindex(): void
    {
        $policy = new IndexabilityPolicy(false, null);

        self::assertFalse($policy->isSiteIndexable());
        self::assertSame('noindex,nofollow', $policy->getDefaultRobots());
        self::assertSame('noindex,nofollow', $policy->resolveRobots(null));
        self::assertSame('noindex,nofollow', $policy->resolveRobots(false));
        self::assertSame('noindex,nofollow', $policy->resolveRobots(true));
    }

    public function testSiteIndexableTrueReturnsIndexUnlessPageOptsOut(): void
    {
        $policy = new IndexabilityPolicy(
            true,
            'https://nidemiel.com',
            '^(nidemiel\.com|www\.nidemiel\.com)$'
        );

        self::assertTrue($policy->isSiteIndexable());
        self::assertSame('index,follow', $policy->getDefaultRobots());
        self::assertSame('index,follow', $policy->resolveRobots(null));
        self::assertSame('index,follow', $policy->resolveRobots(false));
        self::assertSame('noindex,follow', $policy->resolveRobots(true));
    }

    // ---- Base URL : normalisation et fallback ----

    public function testConfiguredBaseUrlIsNormalizedWithoutTrailingSlash(): void
    {
        $policy = new IndexabilityPolicy(
            true,
            'https://nidemiel.com/',
            '^(nidemiel\.com|www\.nidemiel\.com)$'
        );

        self::assertSame('https://nidemiel.com', $policy->getBaseUrl());
    }

    public function testConfiguredBaseUrlIsAlwaysPreferredOverRequest(): void
    {
        $policy = new IndexabilityPolicy(
            true,
            'https://nidemiel.com',
            '^(nidemiel\.com|www\.nidemiel\.com)$'
        );
        $spoofedRequest = Request::create('https://attacker.example/');

        self::assertSame('https://nidemiel.com', $policy->getBaseUrl($spoofedRequest));
    }

    public function testFallbackToRequestOnlyAllowedWhenNotIndexable(): void
    {
        $policy = new IndexabilityPolicy(false, null);
        $request = Request::create('https://dev.local:8443/foo');

        self::assertSame('https://dev.local:8443', $policy->getBaseUrl($request));
    }

    public function testGetBaseUrlReturnsEmptyWhenNoConfigAndNoRequest(): void
    {
        $policy = new IndexabilityPolicy(false, null);

        self::assertSame('', $policy->getBaseUrl());
    }

    // ---- Validation obligatoire quand indexable ----

    // Ces tests fournissent un TRUSTED_HOSTS valide pour isoler les assertions
    // sur APP_BASE_URL. La constante VALID_TRUSTED_HOSTS ci-dessous représente la
    // valeur attendue en production réelle.

    private const VALID_TRUSTED_HOSTS = '^(nidemiel\.com|www\.nidemiel\.com)$';

    public function testIndexableWithEmptyBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_BASE_URL est obligatoire');

        new IndexabilityPolicy(true, '', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithNullBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_BASE_URL est obligatoire');

        new IndexabilityPolicy(true, null, self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithHttpSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('doit utiliser HTTPS');

        new IndexabilityPolicy(true, 'http://nidemiel.com', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithLocalhostThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hôte non public');

        new IndexabilityPolicy(true, 'https://localhost', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithLoopbackIpThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hôte non public');

        new IndexabilityPolicy(true, 'https://127.0.0.1', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithPrivateRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hôte non public');

        new IndexabilityPolicy(true, 'https://192.168.1.10', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithPreviewSubdomainThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hôte non public');

        new IndexabilityPolicy(true, 'https://preview.nidemiel.com', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithStagingSubdomainThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hôte non public');

        new IndexabilityPolicy(true, 'https://staging.nidemiel.com', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithMalformedUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalide');

        new IndexabilityPolicy(true, 'ni un scheme ni un host', self::VALID_TRUSTED_HOSTS);
    }

    public function testNonIndexableAcceptsLocalhostForDev(): void
    {
        $policy = new IndexabilityPolicy(false, 'https://localhost:8443/');

        self::assertSame('https://localhost:8443', $policy->getBaseUrl());
    }

    // ---- Validation TRUSTED_HOSTS ----

    public function testIndexableTrustedHostsAcceptsCanonicalDomains(): void
    {
        $policy = new IndexabilityPolicy(
            true,
            'https://nidemiel.com',
            '^(nidemiel\.com|www\.nidemiel\.com)$'
        );

        self::assertTrue($policy->isSiteIndexable());
    }

    public function testIndexableTrustedHostsRejectsLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS');

        new IndexabilityPolicy(
            true,
            'https://nidemiel.com',
            '^(nidemiel\.com|www\.nidemiel\.com|localhost)$'
        );
    }

    public function testIndexableTrustedHostsRejectsLoopbackIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS');

        new IndexabilityPolicy(
            true,
            'https://nidemiel.com',
            '^(nidemiel\.com|127\.0\.0\.1)$'
        );
    }

    public function testIndexableTrustedHostsRejectsPreviewSubdomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS');

        new IndexabilityPolicy(
            true,
            'https://nidemiel.com',
            '^(nidemiel\.com|preview.nidemiel\.com)$'
        );
    }

    public function testNonIndexableTolerantOnTrustedHostsLocalhost(): void
    {
        // En dev/preview on autorise localhost dans TRUSTED_HOSTS — pas de garde.
        $policy = new IndexabilityPolicy(
            false,
            null,
            '^(localhost|preview\.nidemiel\.com)$'
        );

        self::assertFalse($policy->isSiteIndexable());
    }

    public function testIndexableTrustedHostsEmptyThrows(): void
    {
        // En prod publique, laisser TRUSTED_HOSTS vide expose à l'injection Host
        // (canonical / redirections / mail generation). Doit être bloqué au boot.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS est obligatoire');

        new IndexabilityPolicy(true, 'https://nidemiel.com', '');
    }

    public function testIndexableTrustedHostsTooBroadDotStarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop permissif');

        new IndexabilityPolicy(true, 'https://nidemiel.com', '.*');
    }

    public function testIndexableTrustedHostsTooBroadAnchoredDotStarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop permissif');

        new IndexabilityPolicy(true, 'https://nidemiel.com', '^.*$');
    }

    public function testIndexableTrustedHostsTooBroadDotPlusThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop permissif');

        new IndexabilityPolicy(true, 'https://nidemiel.com', '.+');
    }

    public function testIndexableTrustedHostsUnanchoredThrows(): void
    {
        // "nidemiel.com" sans ancre matche "evil.nidemiel.com.attacker.example".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ancré');

        new IndexabilityPolicy(true, 'https://nidemiel.com', 'nidemiel\.com');
    }

    public function testIndexableTrustedHostsMissingEndAnchorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ancré');

        new IndexabilityPolicy(true, 'https://nidemiel.com', '^nidemiel\.com');
    }

    public function testIndexableTrustedHostsMissingStartAnchorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ancré');

        new IndexabilityPolicy(true, 'https://nidemiel.com', 'nidemiel\.com$');
    }

    public function testNonIndexableAcceptsEmptyTrustedHosts(): void
    {
        // En dev/preview, l'absence de TRUSTED_HOSTS ne doit pas casser le boot.
        $policy = new IndexabilityPolicy(false, null, '');

        self::assertFalse($policy->isSiteIndexable());
    }
}
