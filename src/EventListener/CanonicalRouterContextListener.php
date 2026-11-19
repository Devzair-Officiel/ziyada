<?php

namespace App\EventListener;

use App\Seo\IndexabilityPolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContextAwareInterface;

/**
 * Force le contexte du routeur (scheme + host) à correspondre à `APP_BASE_URL`
 * lorsque le site est indexable, afin que toutes les URLs absolues générées
 * (canonicals, JSON-LD, OG, sitemap) utilisent le domaine canonique et non
 * l'en-tête Host de la requête entrante.
 *
 * Défense en profondeur : `trusted_hosts` bloque déjà les Host frauduleux, mais
 * ce listener garantit qu'aucun cache poisoning n'est possible même si un proxy
 * mal configuré laissait passer un Host inattendu.
 *
 * Priorité 20 : après le RouterListener natif (32) qui initialise le contexte
 * depuis la requête, mais avant les contrôleurs et les listeners applicatifs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class CanonicalRouterContextListener
{
    public function __construct(
        private readonly IndexabilityPolicy $indexability,
        private readonly RequestContextAwareInterface $router,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->indexability->isSiteIndexable()) {
            return;
        }

        $baseUrl = $this->indexability->getBaseUrl();
        if ($baseUrl === '') {
            return;
        }

        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return;
        }

        $context = $this->router->getContext();
        $context->setScheme($parsed['scheme']);
        $context->setHost($parsed['host']);

        if (isset($parsed['port'])) {
            if ($parsed['scheme'] === 'https') {
                $context->setHttpsPort($parsed['port']);
            } else {
                $context->setHttpPort($parsed['port']);
            }
        }
    }
}
