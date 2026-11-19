<?php

namespace App\EventListener;

use App\Seo\IndexabilityPolicy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute un header `X-Robots-Tag: noindex, nofollow` sur toutes les réponses HTTP
 * quand `SITE_INDEXABLE` est à false. C'est une couche de défense en profondeur :
 * le meta robots HTML est déjà défini par le template, mais le header couvre aussi
 * les réponses non-HTML (fichiers, JSON, sitemap, robots.txt).
 *
 * En production publique (`SITE_INDEXABLE=true`), le listener ne fait rien : les
 * directives robots par page sont pilotées par le payload SEO Twig.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
final class IndexabilityPolicyResponseListener
{
    public function __construct(private readonly IndexabilityPolicy $indexability)
    {}

    public function __invoke(ResponseEvent $event): void
    {
        if ($this->indexability->isSiteIndexable()) {
            return;
        }

        $response = $event->getResponse();

        if (!$response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
