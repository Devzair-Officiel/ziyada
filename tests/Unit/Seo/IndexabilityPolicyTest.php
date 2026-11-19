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
            'https://ziyada.fr',
            '^(ziyada\.fr|www\.ziyada\.fr)$'
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
            'https://ziyada.fr/',
            '^(ziyada\.fr|www\.ziyada\.fr)$'
        );

        self::assertSame('https://ziyada.fr', $policy->getBaseUrl());
    }

    public function testConfiguredBaseUrlIsAlwaysPreferredOverRequest(): void
    {
        $policy = new IndexabilityPolicy(
            true,
            'https://ziyada.fr',
            '^(ziyada\.fr|www\.ziyada\.fr)$'
        );
        $spoofedRequest = Request::create('https://attacker.example/');

        self::assertSame('https://ziyada.fr', $policy->getBaseUrl($spoofedRequest));
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

    private const VALID_TRUSTED_HOSTS = '^(ziyada\.fr|www\.ziyada\.fr)$';

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

        new IndexabilityPolicy(true, 'http://ziyada.fr', self::VALID_TRUSTED_HOSTS);
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

        new IndexabilityPolicy(true, 'https://preview.ziyada.fr', self::VALID_TRUSTED_HOSTS);
    }

    public function testIndexableWithStagingSubdomainThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hôte non public');

        new IndexabilityPolicy(true, 'https://staging.ziyada.fr', self::VALID_TRUSTED_HOSTS);
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
            'https://ziyada.fr',
            '^(ziyada\.fr|www\.ziyada\.fr)$'
        );

        self::assertTrue($policy->isSiteIndexable());
    }

    public function testIndexableTrustedHostsRejectsLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS');

        new IndexabilityPolicy(
            true,
            'https://ziyada.fr',
            '^(ziyada\.fr|www\.ziyada\.fr|localhost)$'
        );
    }

    public function testIndexableTrustedHostsRejectsLoopbackIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS');

        new IndexabilityPolicy(
            true,
            'https://ziyada.fr',
            '^(ziyada\.fr|127\.0\.0\.1)$'
        );
    }

    public function testIndexableTrustedHostsRejectsPreviewSubdomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS');

        new IndexabilityPolicy(
            true,
            'https://ziyada.fr',
            '^(ziyada\.fr|preview.ziyada\.fr)$'
        );
    }

    public function testNonIndexableTolerantOnTrustedHostsLocalhost(): void
    {
        // En dev/preview on autorise localhost dans TRUSTED_HOSTS — pas de garde.
        $policy = new IndexabilityPolicy(
            false,
            null,
            '^(localhost|preview\.ziyada\.fr)$'
        );

        self::assertFalse($policy->isSiteIndexable());
    }

    public function testIndexableTrustedHostsEmptyThrows(): void
    {
        // En prod publique, laisser TRUSTED_HOSTS vide expose à l'injection Host
        // (canonical / redirections / mail generation). Doit être bloqué au boot.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_HOSTS est obligatoire');

        new IndexabilityPolicy(true, 'https://ziyada.fr', '');
    }

    public function testIndexableTrustedHostsTooBroadDotStarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop permissif');

        new IndexabilityPolicy(true, 'https://ziyada.fr', '.*');
    }

    public function testIndexableTrustedHostsTooBroadAnchoredDotStarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop permissif');

        new IndexabilityPolicy(true, 'https://ziyada.fr', '^.*$');
    }

    public function testIndexableTrustedHostsTooBroadDotPlusThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop permissif');

        new IndexabilityPolicy(true, 'https://ziyada.fr', '.+');
    }

    public function testIndexableTrustedHostsUnanchoredThrows(): void
    {
        // "ziyada.fr" sans ancre matche "evil.ziyada.fr.attacker.example".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ancré');

        new IndexabilityPolicy(true, 'https://ziyada.fr', 'ziyada\.fr');
    }

    public function testIndexableTrustedHostsMissingEndAnchorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ancré');

        new IndexabilityPolicy(true, 'https://ziyada.fr', '^ziyada\.fr');
    }

    public function testIndexableTrustedHostsMissingStartAnchorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ancré');

        new IndexabilityPolicy(true, 'https://ziyada.fr', 'ziyada\.fr$');
    }

    public function testNonIndexableAcceptsEmptyTrustedHosts(): void
    {
        // En dev/preview, l'absence de TRUSTED_HOSTS ne doit pas casser le boot.
        $policy = new IndexabilityPolicy(false, null, '');

        self::assertFalse($policy->isSiteIndexable());
    }
}
