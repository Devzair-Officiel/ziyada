<?php

namespace App\Controller;

use App\Repository\BlogRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\ProductRepository;
use App\Seo\IndexabilityPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Contrôleur qui génère dynamiquement le sitemap XML du site (pages statiques, produits, catégories, articles de blog).
 *
 * Respecte `IndexabilityPolicy` : quand le site n'est pas indexable, robots.txt retourne
 * un `Disallow: /` complet et le sitemap n'expose pas d'URLs.
 */
final class SitemapController extends AbstractController
{
    public function __construct(
        private ProductRepository  $productRepo,
        private CategoryRepository $categoryRepo,
        private BlogRepository     $blogRepo,
        private PageRepository     $pageRepo,
        private IndexabilityPolicy $indexability,
    ) {}

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], defaults: ['_format' => 'xml'])]
    public function sitemap(UrlGeneratorInterface $urlGenerator): Response
    {
        // Site non indexable : sitemap vide plutôt que 404 (les crawlers reçoivent une réponse valide).
        if (!$this->indexability->isSiteIndexable()) {
            $response = new Response(
                $this->renderView('sitemap/index.xml.twig', ['urls' => []]),
                200,
                ['Content-Type' => 'application/xml; charset=UTF-8']
            );
            $response->setPublic();
            $response->setSharedMaxAge(3600);

            return $response;
        }

        $urls = [];

        // Pages statiques indexables
        $statics = [
            ['route' => 'app_home',             'priority' => '1.0', 'changefreq' => 'weekly'],
            ['route' => 'app_blog',             'priority' => '0.8', 'changefreq' => 'weekly'],
            ['route' => 'app_guide_sensoriel',  'priority' => '0.7', 'changefreq' => 'monthly'],
            ['route' => 'app_faq',              'priority' => '0.6', 'changefreq' => 'monthly'],
        ];

        foreach ($statics as $s) {
            $urls[] = [
                'loc'        => $urlGenerator->generate($s['route'], [], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => $s['priority'],
                'changefreq' => $s['changefreq'],
            ];
        }

        // Catégories : filtrer les entités avec opt-out SEO
        foreach ($this->categoryRepo->findAll() as $category) {
            if (!$category->getSlug() || $category->isSeoNoindex()) {
                continue;
            }
            $urls[] = [
                'loc'        => $urlGenerator->generate('app_category', ['categoryName' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod'    => null,
                'priority'   => '0.8',
                'changefreq' => 'weekly',
            ];
        }

        // Produits disponibles : filtrer opt-out SEO + mise en pause qualité
        foreach ($this->productRepo->findBy(['isAvailable' => true]) as $product) {
            if (!$product->getSlug() || $product->isSeoNoindex() || $product->isOnQualityHold()) {
                continue;
            }
            $urls[] = [
                'loc'        => $urlGenerator->generate('app_product_by_slug', ['slug' => $product->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod'    => $product->getUpdatedAt()?->format('Y-m-d'),
                'priority'   => '0.9',
                'changefreq' => 'weekly',
            ];
        }

        // Articles de blog publiés : filtrer opt-out SEO
        foreach ($this->blogRepo->findBy(['isPublished' => true]) as $blog) {
            if (!$blog->getSlug() || $blog->isSeoNoindex()) {
                continue;
            }
            $urls[] = [
                'loc'        => $urlGenerator->generate('app_blog_show', ['slug' => $blog->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod'    => ($blog->getUpdatedAt() ?? $blog->getCreatedAt())?->format('Y-m-d'),
                'priority'   => '0.7',
                'changefreq' => 'monthly',
            ];
        }

        // Pages CMS (mentions légales, CGV, politique de confidentialité, etc.)
        foreach ($this->pageRepo->findAll() as $page) {
            if (!$page->getSlug()) {
                continue;
            }
            $urls[] = [
                'loc'        => $urlGenerator->generate('app_page', ['slug' => $page->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'priority'   => '0.4',
                'changefreq' => 'yearly',
            ];
        }

        $response = new Response(
            $this->renderView('sitemap/index.xml.twig', ['urls' => $urls]),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );

        $response->setPublic();
        $response->setSharedMaxAge(3600); // cache 1h

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(UrlGeneratorInterface $urlGenerator): Response
    {
        // Site non indexable : refuser explicitement toute exploration (env de préprod, staging, etc.).
        if (!$this->indexability->isSiteIndexable()) {
            $content = <<<TXT
            User-agent: *
            Disallow: /
            TXT;

            return new Response(
                trim(preg_replace('/^            /m', '', $content)),
                200,
                ['Content-Type' => 'text/plain']
            );
        }

        $sitemap = $urlGenerator->generate('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Politique : ne bloquer via robots.txt que les URLs qui ne peuvent PAS émettre
        // un `<meta name="robots" content="noindex">` (endpoints API, webhooks, callbacks OAuth,
        // routes sans HTML). Pour les pages HTML fonctionnelles (login, register, cart, compare,
        // wishlist, account, checkout, reset-password), la désindexation passe uniquement par la
        // meta robots — le crawler doit pouvoir accéder à la page pour lire la directive. Sinon
        // on obtient l'état "Indexed, though blocked by robots.txt" dans Search Console.
        $content = <<<TXT
        User-agent: *
        Allow: /

        # Espace admin (protégé par IsGranted, aucune valeur SEO)
        Disallow: /admin

        # Endpoints techniques sans rendu HTML (pas de meta robots possible)
        Disallow: /api/
        Disallow: /product/get/
        Disallow: /product/search
        Disallow: /webhooks/
        Disallow: /stripe/
        Disallow: /verify/
        Disallow: /connect/
        Disallow: /logout

        Sitemap: {$sitemap}
        TXT;

        return new Response(
            trim(preg_replace('/^        /m', '', $content)),
            200,
            ['Content-Type' => 'text/plain']
        );
    }
}
