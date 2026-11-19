<?php

namespace App\Controller;

use App\Seo\SeoResolver;
use App\Service\CompareService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Contrôleur public pour la comparaison de produits : affichage, ajout et retrait de la liste de comparaison.
 */
class CompareController extends AbstractController
{
    public function __construct(
        private CompareService $compareService,
        private SeoResolver $seoResolver,
    ) {}

    #[Route('/compare', name: 'app_compare')]
    public function index(Request $request): Response
    {
        $data = $this->compareService->getCompareDetails();

        $seo = $this->seoResolver->forStaticPage([
            'title'       => 'Comparateur de miels — Ziyada',
            'description' => 'Comparez plusieurs miels côte à côte : caractéristiques, dégustation, données d’analyse laboratoire.',
            'route'       => 'app_compare',
            'robots'      => 'noindex,follow',
        ], $request);

        return $this->render('compare/index.html.twig', [
            'products' => $data['products'],
            'labRows'  => $data['labRows'],
            'seo'      => $seo,
        ]);
    }

    #[Route('/compare/add/{productId}', name: 'app_add_to_compare', methods: ['GET', 'POST'])]
    public function addToCompare(int $productId): Response
    {
        $this->compareService->addToCompare($productId);

        return $this->json([
            'ok'    => true,
            'count' => $this->compareService->getCompareDetails()['count'],
        ]);
    }

    #[Route('/compare/remove/{productId}', name: 'app_remove_compare', methods: ['GET', 'POST'])]
    public function removeToCompare(int $productId): Response
    {
        $this->compareService->removeToCompare($productId);

        return $this->json([
            'ok'    => true,
            'count' => $this->compareService->getCompareDetails()['count'],
        ]);
    }

    #[Route('/compare/get', name: 'app_get_compare')]
    public function getCompare(): Response
    {
        return $this->json([
            'count' => $this->compareService->getCompareDetails()['count'],
        ]);
    }
}
