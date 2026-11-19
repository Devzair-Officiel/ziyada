<?php

namespace App\Controller;

use App\Repository\FaqRepository;
use App\Repository\ProductRepository;
use App\Seo\SeoResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Univers grossiste : page principale + fiches produits en gros.
 */
final class GrossisteController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $productRepo,
        private readonly FaqRepository $faqRepo,
        private readonly SeoResolver $seoResolver,
    ) {}

    #[Route('/grossiste-miel', name: 'app_grossiste_index')]
    public function index(Request $request): Response
    {
        $products = $this->productRepo->findWholesaleProducts();
        $faqs     = $this->faqRepo->findActiveWholesale();

        $seo = $this->seoResolver->forStaticPage([
            'title'       => 'Miel en gros pour professionnels — Nidemiel',
            'description' => 'Approvisionnement de miels sélectionnés pour épiceries, restaurants et revendeurs. Tarifs dégressifs, analyses laboratoire disponibles, livraison France.',
            'route'       => 'app_grossiste_index',
            'breadcrumbs' => [
                ['name' => 'Accueil',   'url' => $this->generateUrl('app_home', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
                ['name' => 'Grossiste', 'url' => $this->generateUrl('app_grossiste_index', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ],
        ], $request);

        return $this->render('grossiste/index.html.twig', [
            'products' => $products,
            'faqs'     => $faqs,
            'seo'      => $seo,
        ]);
    }

    #[Route('/grossiste-miel/{slug}', name: 'app_grossiste_product', requirements: ['slug' => '[a-z0-9-]+'])]
    public function showProduct(string $slug, Request $request): Response
    {
        // Le slug grossiste se termine par "-en-gros", on retrouve le produit retail via le slug de base
        $retailSlug = preg_replace('/-en-gros$/', '', $slug);

        $product = $this->productRepo->findOneBySlugWithRelations($retailSlug);

        if (!$product) {
            throw $this->createNotFoundException('Produit grossiste introuvable.');
        }

        // Images dédiées grossiste ; fallback sur toutes les images si aucune marquée.
        // Les vidéos ne sont pas incluses dans la galerie grossiste (zoom = image uniquement).
        $allImages       = $product->getMedias()->filter(
            fn($m) => $m->isImage() && is_string($m->getFilename()) && $m->getFilename() !== ''
        );
        $wholesaleImages = $allImages->filter(fn($m) => $m->isWholesale() === true);
        $media           = array_values(($wholesaleImages->count() > 0 ? $wholesaleImages : $allImages)->toArray());

        $seo = $this->seoResolver->forStaticPage([
            'title'       => sprintf('%s en gros — Nidemiel', $product->getTitle()),
            'description' => sprintf('Achetez %s en volume pour votre activité : conditionnements pro, tarifs dégressifs, traçabilité complète.', $product->getTitle()),
            'canonical'   => $this->generateUrl('app_grossiste_product', ['slug' => $slug], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            'breadcrumbs' => [
                ['name' => 'Accueil',        'url' => $this->generateUrl('app_home',            [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
                ['name' => 'Grossiste',      'url' => $this->generateUrl('app_grossiste_index', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
                ['name' => $product->getTitle(), 'url' => $this->generateUrl('app_grossiste_product', ['slug' => $slug], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ],
        ], $request);

        return $this->render('grossiste/show.html.twig', [
            'product'         => $product,
            'grossisteSlug'   => $slug,
            'media'           => $media,
            'relatedProducts' => $product->getRelatedProducts(),
            'seo'             => $seo,
        ]);
    }
}
