<?php

namespace App\Seo;

use Symfony\Component\HttpFoundation\Request;

/**
 * Centralise les décisions d'indexabilité (SEO) : autorise ou bloque l'indexation
 * du site entier, fournit la directive robots par défaut et l'URL de base canonique.
 *
 * - `SITE_INDEXABLE`  : "1"/"true" pour autoriser l'indexation, sinon bloque tout le site.
 * - `APP_BASE_URL`    : URL absolue du site (schéma + host, ex. https://ziyada.fr).
 *                       OBLIGATOIRE quand `SITE_INDEXABLE=true` : sinon les canonicals et
 *                       le sitemap dépendraient du Host HTTP, ce qui expose à l'injection
 *                       de canonical via un faux header Host.
 *                       Autorisée à null seulement quand le site n'est pas indexable
 *                       (fallback sur Request::getSchemeAndHttpHost()).
 */
final class IndexabilityPolicy
{
    /**
     * Hostnames toujours refusés dans APP_BASE_URL quand le site est indexable.
     * Une preview *.vercel.app / *.ziyada-preview.fr etc. doit rester en SITE_INDEXABLE=false.
     */
    private const FORBIDDEN_HOST_PATTERNS = [
        '/^localhost$/i',
        '/^127\./',
        '/^0\.0\.0\.0$/',
        '/^::1$/',
        '/^10\./',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
        '/^192\.168\./',
        '/\.local$/i',
        '/\.test$/i',
        '/\.preview\./i',
        '/preview\./i',
        '/staging\./i',
        '/^preprod\./i',
    ];

    private readonly ?string $baseUrl;

    /**
     * @throws \InvalidArgumentException si SITE_INDEXABLE=true et APP_BASE_URL absent/invalide,
     *                                   ou si TRUSTED_HOSTS contient un motif localhost en prod publique.
     */
    public function __construct(
        private readonly bool $siteIndexable,
        ?string $configuredBaseUrl,
        ?string $trustedHosts = null,
    ) {
        $this->baseUrl = $this->validateAndNormalize($configuredBaseUrl);
        $this->validateTrustedHosts($trustedHosts);
    }

    public function isSiteIndexable(): bool
    {
        return $this->siteIndexable;
    }

    /**
     * Directive robots à appliquer par défaut si aucune règle spécifique à la page n'existe.
     */
    public function getDefaultRobots(): string
    {
        return $this->siteIndexable ? 'index,follow' : 'noindex,nofollow';
    }

    /**
     * Résout la directive robots effective pour une page donnée.
     * Ordre de priorité : blocage global > opt-out page > défaut indexable.
     */
    public function resolveRobots(?bool $pageNoindex): string
    {
        if (!$this->siteIndexable) {
            return 'noindex,nofollow';
        }

        if ($pageNoindex === true) {
            return 'noindex,follow';
        }

        return 'index,follow';
    }

    /**
     * URL de base absolue (schéma + host, sans slash final) à utiliser pour les canonicals
     * et le sitemap.
     *
     * - Si le site est indexable : APP_BASE_URL est obligatoire (déjà validé au boot).
     *   La Request n'est JAMAIS utilisée pour éviter l'injection via Host header.
     * - Si le site n'est pas indexable : APP_BASE_URL configuré prioritaire, sinon
     *   fallback sur la Request courante (mode dev/preview).
     */
    public function getBaseUrl(?Request $request = null): string
    {
        if ($this->baseUrl !== null) {
            return $this->baseUrl;
        }

        if (!$this->siteIndexable && $request !== null) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return '';
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateAndNormalize(?string $configuredBaseUrl): ?string
    {
        $trimmed = $configuredBaseUrl !== null ? trim($configuredBaseUrl) : '';

        if ($trimmed === '') {
            if ($this->siteIndexable) {
                throw new \InvalidArgumentException(
                    'APP_BASE_URL est obligatoire quand SITE_INDEXABLE=true. '
                    . 'Définir par exemple APP_BASE_URL=https://ziyada.fr.'
                );
            }

            return null;
        }

        $normalized = rtrim($trimmed, '/');
        $parsed = parse_url($normalized);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException(sprintf(
                'APP_BASE_URL "%s" est invalide : format attendu https://exemple.com',
                $configuredBaseUrl
            ));
        }

        if (!$this->siteIndexable) {
            // En mode non indexable, on tolère des URLs de dev (localhost, http, etc.)
            // mais on garde la normalisation.
            return $normalized;
        }

        if ($parsed['scheme'] !== 'https') {
            throw new \InvalidArgumentException(sprintf(
                'APP_BASE_URL doit utiliser HTTPS quand SITE_INDEXABLE=true, reçu "%s".',
                $configuredBaseUrl
            ));
        }

        foreach (self::FORBIDDEN_HOST_PATTERNS as $pattern) {
            if (preg_match($pattern, $parsed['host']) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'APP_BASE_URL "%s" pointe vers un hôte non public (localhost, IP privée, staging, preview…). '
                    . 'Utilisez le domaine de production réel ou repassez SITE_INDEXABLE à false.',
                    $configuredBaseUrl
                ));
            }
        }

        return $normalized;
    }

    /**
     * En prod publique (`SITE_INDEXABLE=true`), le regex `TRUSTED_HOSTS` est
     * OBLIGATOIRE et doit être restrictif : il ne doit pas autoriser localhost,
     * les IP privées ni des motifs trop larges (regex qui match tout, hosts vides).
     *
     * @throws \InvalidArgumentException
     */
    private function validateTrustedHosts(?string $trustedHosts): void
    {
        if (!$this->siteIndexable) {
            return;
        }

        $trimmed = $trustedHosts !== null ? trim($trustedHosts) : '';

        if ($trimmed === '') {
            throw new \InvalidArgumentException(
                'TRUSTED_HOSTS est obligatoire quand SITE_INDEXABLE=true. '
                . 'Définir par exemple TRUSTED_HOSTS=^(ziyada\\.com|www\\.ziyada\\.com)$.'
            );
        }

        $forbiddenSubstrings = [
            'localhost',
            '127.0.0.1',
            '127\.0\.0\.1',
            '0.0.0.0',
            '0\.0\.0\.0',
            '10\.',
            '192\.168\.',
            '.local',
            '.test',
            'preview.',
            'staging.',
            'preprod.',
        ];

        foreach ($forbiddenSubstrings as $needle) {
            if (str_contains($trimmed, $needle)) {
                throw new \InvalidArgumentException(sprintf(
                    'TRUSTED_HOSTS "%s" contient un motif non public ("%s"). '
                    . 'En prod publique (SITE_INDEXABLE=true), n\'autoriser que le domaine canonique '
                    . '(ex. ^(ziyada\\.com|www\\.ziyada\\.com)$).',
                    $trimmed,
                    $needle
                ));
            }
        }

        // Refuser les regex trop larges qui matchent tout ou presque tout.
        // Exemples interdits : ".*", ".+", "^.*$", "", ".", "^$".
        $tooBroadPatterns = [
            '/^\^?\.[*+]\$?$/',   // ".*", "^.*$", ".+", "^.+$"
            '/^\^?\.\$?$/',        // ".", "^.$"
            '/^\^\$$/',            // "^$"
        ];

        foreach ($tooBroadPatterns as $regex) {
            if (preg_match($regex, $trimmed) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'TRUSTED_HOSTS "%s" est trop permissif (matche potentiellement tout Host header). '
                    . 'Utiliser un regex ancré et restrictif comme ^(ziyada\\.com|www\\.ziyada\\.com)$.',
                    $trimmed
                ));
            }
        }

        // Refuser un regex non ancré (sans ^ ou sans $) : sinon "ziyada.fr" matche
        // aussi "evil.ziyada.fr.attacker.example".
        if (!str_starts_with($trimmed, '^') || !str_ends_with($trimmed, '$')) {
            throw new \InvalidArgumentException(sprintf(
                'TRUSTED_HOSTS "%s" doit être un regex ancré (commencer par ^ et finir par $). '
                . 'Exemple : ^(ziyada\\.com|www\\.ziyada\\.com)$.',
                $trimmed
            ));
        }
    }
}
