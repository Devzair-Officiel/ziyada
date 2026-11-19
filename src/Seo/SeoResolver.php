<?php

namespace App\Seo;

use App\Entity\Blog;
use App\Entity\Category;
use App\Entity\Faq;
use App\Entity\Media;
use App\Entity\Product;
use App\Repository\ReviewRepository;
use App\Repository\SettingRepository;
use App\Service\MediaFileManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Construit un payload SEO prêt à être injecté dans les templates Twig.
 *
 * Rôle :
 * centraliser la logique SEO des pages indexables
 * (produit, catégorie, blog, page statique), afin d'éviter
 * de disperser les règles SEO dans les contrôleurs et les templates.
 *
 * Objectifs :
 * - garantir des fallbacks cohérents ;
 * - produire des URLs absolues propres ;
 * - générer des données structurées adaptées au type de page ;
 * - garder les contrôleurs simples et lisibles.
 */
final class SeoResolver
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ReviewRepository $reviewRepository,
        private readonly SettingRepository $settingRepository,
        private readonly IndexabilityPolicy $indexabilityPolicy,
        private readonly string $brandName,
        private readonly string $publicDir,
        private readonly string $defaultOgImage = '/assets/images/setting/og-default.png',
    ) {}

    /**
     * Construit le SEO complet pour une fiche produit.
     */
    public function forProduct(Product $product, Request $request): SeoPayload
    {
        $canonical = $product->getSeoCanonicalOverride()
            ?: $this->urlGenerator->generate(
                'app_product_by_slug',
                [
                    'slug' => $product->getSlug(),
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

        $title = $product->getSeoTitle();
        if (!$title) {
            $origin = trim((string) $product->getOriginCountry());

            $title = $origin !== ''
                ? sprintf('%s – %s | %s', $product->getTitle(), $origin, $this->brandName)
                : sprintf('%s | %s', $product->getTitle(), $this->brandName);
        }

        $description = $product->getSeoDescription();
        if (!$description) {
            $sourceDescription = $product->getDescription()
                ?: $product->getMoreDescription()
                ?: sprintf('Achetez %s, un miel naturel 100%% authentique. Livraison rapide en France. Qualité artisanale garantie chez %s.', $product->getTitle(), $this->brandName);

            $description = $this->truncateText($sourceDescription, 160);
        }

        // Un miel mis en pause qualité est noindex tant que le motif est actif :
        // il ne doit pas remonter dans les SERPs, même s'il reste accessible en direct.
        $robots = $this->indexabilityPolicy->resolveRobots(
            $product->isSeoNoindex() || $product->isOnQualityHold(),
        );
        $image = $this->resolveProductImage($product, $request);

        $og = $this->buildOg(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $image,
            type: 'product'
        );

        $ratingSummary = $this->reviewRepository->findRatingSummaryForProducts([$product->getId()]);
        $rating = $ratingSummary[$product->getId()] ?? null;
        $reviews = $this->reviewRepository->findApprovedByProduct($product);

        $jsonLd = [
            $this->productJsonLd($product, $canonical, $image, $rating, $reviews),
            $this->breadcrumbJsonLd([
                [
                    'name' => 'Accueil',
                    'url' => $this->absoluteRoute('app_home'),
                ],
                [
                    'name' => $product->getTitle(),
                    'url' => $canonical,
                ],
            ]),
        ];

        return new SeoPayload(
            $title,
            $description,
            $canonical,
            $robots,
            $og,
            $jsonLd
        );
    }

    /**
     * Construit le SEO complet pour une page catégorie.
     */
    public function forCategory(Category $category, Request $request): SeoPayload
    {
        $canonical = $category->getSeoCanonicalOverride()
            ?: $this->urlGenerator->generate(
                'app_category',
                ['categoryName' => $category->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

        $title = $category->getSeoTitle()
            ?: sprintf('%s | %s', $category->getTitle(), $this->brandName);

        $description = $category->getSeoDescription();
        if (!$description) {
            $sourceDescription = $category->getDescription()
                ?: $category->getIntro()
                ?: sprintf('Découvrez notre sélection de %s : miels naturels, artisanaux, d\'origine contrôlée. Livraison offerte dès 50€ chez %s.', $category->getTitle(), $this->brandName);

            $description = $this->truncateText($sourceDescription, 160);
        }

        $robots = $this->indexabilityPolicy->resolveRobots($category->isSeoNoindex());
        $image = $this->resolveCategoryImage($category, $request);

        $og = $this->buildOg(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $image,
            type: 'website'
        );

        $jsonLd = [
            $this->collectionPageJsonLd(
                name: $category->getTitle(),
                description: $description,
                canonical: $canonical
            ),
            $this->breadcrumbJsonLd([
                [
                    'name' => 'Accueil',
                    'url' => $this->absoluteRoute('app_home'),
                ],
                [
                    'name' => $category->getTitle(),
                    'url' => $canonical,
                ],
            ]),
        ];

        return new SeoPayload($title, $description, $canonical, $robots, $og, $jsonLd);
    }

    /**
     * Construit le SEO complet pour un article de blog.
     */
    public function forBlog(Blog $blog, Request $request): SeoPayload
    {
        $canonical = $blog->getSeoCanonicalOverride()
            ?: $this->urlGenerator->generate(
                'app_blog_show',
                ['slug' => $blog->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

        $title = $blog->getSeoTitle()
            ?: sprintf('%s | Blog %s', $blog->getTitle(), $this->brandName);

        $description = $blog->getSeoDescription();
        if (!$description) {
            $sourceDescription = $blog->getDescription()
                ?: $blog->getContent()
                ?: sprintf('Tout savoir sur %s : conseils d\'experts, bienfaits et utilisation du miel naturel. Par les apiculteurs de %s.', $blog->getTitle(), $this->brandName);

            $description = $this->truncateText($sourceDescription, 160);
        }

        $robots = $this->indexabilityPolicy->resolveRobots($blog->isSeoNoindex());
        $image = $this->resolveBlogImage($blog, $request);

        $og = $this->buildOg(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $image,
            type: 'article'
        );

        $jsonLd = [
            $this->blogPostingJsonLd($blog, $canonical, $image),
            $this->breadcrumbJsonLd([
                [
                    'name' => 'Accueil',
                    'url' => $this->absoluteRoute('app_home'),
                ],
                [
                    'name' => 'Blog',
                    'url' => $this->absoluteRoute('app_blog'),
                ],
                [
                    'name' => $blog->getTitle(),
                    'url' => $canonical,
                ],
            ]),
        ];

        return new SeoPayload($title, $description, $canonical, $robots, $og, $jsonLd);
    }

    /**
     * Construit le SEO d'une page statique sans dépendre d'une entité dédiée.
     *
     * Exemple :
     * livraison, contact, paiement, CGV, à propos...
     *
     * @param array{
     *     title: string,
     *     description: string,
     *     route?: string,
     *     routeParams?: array<string, scalar>,
     *     canonical?: string,
     *     robots?: string,
     *     ogImage?: string|array|null,
     *     ogType?: string,
     *     breadcrumbs?: array<int, array{name: string, url: string}>,
     *     organization?: array{name: string, url: string, logo?: string, email?: string, phone?: string, address?: string, sameAs?: list<string>},
     *     faq?: array<int, array{question: string, answer: string}>
     * } $data
     */
    public function forStaticPage(array $data, Request $request): SeoPayload
    {
        $canonical = $data['canonical']
            ?? $this->urlGenerator->generate(
                $data['route'],
                $data['routeParams'] ?? [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

        $title = $data['title'];
        $description = $this->truncateText($data['description'], 160);
        $robots = $this->resolveStaticRobots($data['robots'] ?? null);

        $image = $this->toAbsoluteIfNeeded($data['ogImage'] ?? $this->defaultOgImage, $request);

        $og = $this->buildOg(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $image,
            type: $data['ogType'] ?? 'website'
        );

        $jsonLd = [
            $this->webPageJsonLd($title, $description, $canonical),
        ];

        if (!empty($data['breadcrumbs'])) {
            $jsonLd[] = $this->breadcrumbJsonLd($data['breadcrumbs']);
        }

        if (!empty($data['organization'])) {
            $jsonLd[] = $this->organizationJsonLd($data['organization']);
        }

        if (!empty($data['faq'])) {
            $jsonLd[] = $this->faqPageJsonLd($data['faq']);
        }

        return new SeoPayload($title, $description, $canonical, $robots, $og, $jsonLd);
    }

    /**
     * Génère un fil d'Ariane structuré en JSON-LD.
     *
     * Objectif :
     * aider les moteurs à comprendre la hiérarchie logique de la page.
     *
     * @param array<int, array{name: string, url: string}> $items
     * @return array<string, mixed>
     */
    private function breadcrumbJsonLd(array $items): array
    {
        $list = [];

        foreach ($items as $index => $item) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    /**
     * Génère les données structurées d'un produit simple.
     */
    private function productJsonLd(Product $product, string $canonical, ?string $image, ?array $rating = null, array $reviews = []): array
    {
        $description = $product->getSeoDescription()
            ?: $product->getDescription()
            ?: $product->getMoreDescription()
            ?: $product->getTitle();

        $effectivePriceCents = $product->getEffectivePrice();
        $price = $effectivePriceCents !== null
            ? number_format($effectivePriceCents / 100, 2, '.', '')
            : null;

        $isAvailable = ($product->isAvailable() ?? true) && (($product->getStock() ?? 0) > 0);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->getTitle(),
            'url' => $canonical,
            'description' => $this->truncateText(strip_tags((string) $description), 300),
            'brand' => [
                '@type' => 'Brand',
                'name' => $this->brandName,
            ],
        ];

        if ($image !== null) {
            $data['image'] = [$image];
        }

        if ($product->getWeightGrams() !== null) {
            $data['weight'] = [
                '@type' => 'QuantitativeValue',
                'value' => $product->getWeightGrams(),
                'unitCode' => 'GRM',
            ];
        }

        if ($rating !== null && ($rating['count'] ?? 0) > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $rating['average'], 1),
                'reviewCount' => (int) $rating['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        $reviewSchemas = [];
        foreach (\array_slice($reviews, 0, 5) as $review) {
            $user = $review->getUser();
            $fullName = $user?->getFullName() ?: 'Client';
            // Masquer le nom de famille pour la vie privée : "Marie D."
            $parts = explode(' ', trim($fullName), 2);
            $displayName = $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '');

            $schema = [
                '@type' => 'Review',
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => $review->getRating(),
                    'bestRating' => 5,
                    'worstRating' => 1,
                ],
                'author' => [
                    '@type' => 'Person',
                    'name' => $displayName,
                ],
            ];

            if ($review->getComment()) {
                $schema['reviewBody'] = $this->truncateText($review->getComment(), 500);
            }

            if ($review->getCreatedAt()) {
                $schema['datePublished'] = $review->getCreatedAt()->format('Y-m-d');
            }

            $reviewSchemas[] = $schema;
        }

        if ($reviewSchemas !== []) {
            $data['review'] = $reviewSchemas;
        }

        if ($price !== null) {
            $data['offers'] = [
                '@type' => 'Offer',
                'url' => $canonical,
                'priceCurrency' => 'EUR',
                'price' => $price,
                'availability' => $isAvailable
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => [
                    '@type' => 'Organization',
                    'name' => $this->brandName,
                ],
            ];
        }

        return $data;
    }

    /**
     * Génère les données structurées d'une page de collection.
     */
    private function collectionPageJsonLd(string $name, string $description, string $canonical): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $description,
            'url' => $canonical,
        ];
    }

    /**
     * Génère les données structurées d'un article de blog.
     */
    private function blogPostingJsonLd(Blog $blog, string $canonical, ?string $image): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $blog->getTitle(),
            'mainEntityOfPage' => $canonical,
            'datePublished' => $blog->getCreatedAt()?->format(\DATE_ATOM),
            'dateModified' => ($blog->getUpdatedAt() ?? $blog->getCreatedAt())?->format(\DATE_ATOM),
            'author' => [
                '@type' => 'Organization',
                'name' => $this->brandName,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $this->brandName,
            ],
            'description' => $this->truncateText(
                $blog->getSeoDescription() ?: ($blog->getDescription() ?: strip_tags((string) $blog->getContent())),
                300
            ),
            'url' => $canonical,
        ];

        if ($image !== null) {
            $data['image'] = [$image];
        }

        return array_filter($data, static fn($value) => $value !== null);
    }

    /**
     * Construit le SEO de la page FAQ avec FAQPage JSON-LD.
     *
     * @param Faq[] $faqs
     */
    public function forFaq(array $faqs, Request $request): SeoPayload
    {
        $canonical = $this->absoluteRoute('app_faq');
        $title = 'FAQ miel : qualité, origine, conservation, cristallisation';
        $description = 'Retrouvez les réponses aux questions fréquentes sur nos miels : origine, analyses, conservation, cristallisation, choix des goûts, commande et livraison.';
        $robots = $this->indexabilityPolicy->getDefaultRobots();
        $image = $this->toAbsoluteIfNeeded($this->defaultOgImage, $request);

        $og = $this->buildOg(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $image,
            type: 'website'
        );

        $faqItems = array_map(static fn($f) => [
            'question' => $f->getQuestion(),
            'answer'   => strip_tags((string) $f->getAnswer()),
        ], $faqs);

        $jsonLd = [
            $this->webPageJsonLd($title, $description, $canonical),
            $this->breadcrumbJsonLd([
                ['name' => 'Accueil', 'url' => $this->absoluteRoute('app_home')],
                ['name' => 'FAQ', 'url' => $canonical],
            ]),
        ];

        if (count($faqItems) >= 2) {
            $jsonLd[] = $this->faqPageJsonLd($faqItems);
        }

        return new SeoPayload($title, $description, $canonical, $robots, $og, $jsonLd);
    }

    /**
     * Construit le SEO de la page d'accueil avec Organisation + WebSite JSON-LD.
     */
    public function forHome(Request $request): SeoPayload
    {
        $homeUrl = $this->absoluteRoute('app_home');
        $baseUrl = $this->indexabilityPolicy->getBaseUrl($request);
        if ($baseUrl === '') {
            $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        }
        $searchUrl = $baseUrl . '/product/search?term={search_term_string}';

        $canonical = $homeUrl;
        $title = 'Ziyada — Une sélection de miels choisis avec attention';
        $description = 'Ziyada propose une sélection resserrée de miels choisis pour leur origine, leur profil gustatif et la clarté des informations fournies. Miel de jujubier du Yémen, Manuka, thym, avec analyses disponibles.';
        $robots = $this->indexabilityPolicy->getDefaultRobots();
        $image = $this->toAbsoluteIfNeeded($this->defaultOgImage, $request);

        $og = $this->buildOg(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $image,
            type: 'website'
        );

        $setting = $this->settingRepository->findOneForLayout();
        $sameAs = array_values(array_filter([
            $setting['facebookLink'] ?? null,
            $setting['instaLink'] ?? null,
            $setting['youtubeLink'] ?? null,
        ], static fn(?string $v): bool => $v !== null && $v !== ''));

        $jsonLd = [
            $this->webPageJsonLd($title, $description, $canonical),
            $this->webSiteJsonLd($homeUrl, $searchUrl),
            $this->organizationJsonLd([
                'name' => $this->brandName,
                'url' => $homeUrl,
                'logo' => $baseUrl . $this->defaultOgImage,
                'sameAs' => $sameAs,
            ]),
            $this->breadcrumbJsonLd([
                ['name' => 'Accueil', 'url' => $homeUrl],
            ]),
        ];

        return new SeoPayload($title, $description, $canonical, $robots, $og, $jsonLd);
    }

    /**
     * Génère les données structurées WebSite avec SearchAction.
     * Permet à Google d'afficher un champ de recherche directement dans ses résultats.
     */
    private function webSiteJsonLd(string $homeUrl, string $searchUrlTemplate): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'url' => $homeUrl,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchUrlTemplate,
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * Génère les données structurées d'une organisation.
     *
     * @param array{name: string, url: string, logo?: string, email?: string, phone?: string, address?: string, sameAs?: list<string>} $data
     */
    private function organizationJsonLd(array $data): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $data['name'],
            'url' => $data['url'],
        ];

        if (!empty($data['logo'])) {
            $schema['logo'] = $data['logo'];
        }

        if (!empty($data['email'])) {
            $schema['email'] = $data['email'];
        }

        if (!empty($data['phone'])) {
            $schema['telephone'] = $data['phone'];
        }

        if (!empty($data['address'])) {
            $schema['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $data['address'],
                'addressCountry' => 'FR',
            ];
        }

        if (!empty($data['sameAs'])) {
            $schema['sameAs'] = $data['sameAs'];
        }

        return $schema;
    }

    /**
     * Génère les données structurées d'une FAQ.
     *
     * @param array<int, array{question: string, answer: string}> $items
     */
    private function faqPageJsonLd(array $items): array
    {
        $entities = [];
        foreach ($items as $item) {
            if (empty($item['question']) || empty($item['answer'])) {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $this->truncateText(strip_tags($item['answer']), 500),
                ],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * Génère les données structurées d'une page statique simple.
     */
    private function webPageJsonLd(string $title, string $description, string $canonical): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $canonical,
        ];
    }

    /**
     * Construit le bloc Open Graph commun.
     *
     * @return array<string, string|null>
     */
    private function buildOg(
        string $title,
        string $description,
        string $canonical,
        ?string $image,
        string $type = 'website'
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'url' => $canonical,
            'type' => $type,
            'image' => $image,
        ];
    }

    /**
     * Génère une URL absolue à partir d'une route Symfony.
     *
     * @param array<string, scalar> $params
     */
    private function absoluteRoute(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Récupère l'image principale d'un produit.
     */
    private function resolveProductImage(Product $product, Request $request): ?string
    {
        $manual = $this->manualOgImage($product->getSeoOgImage(), MediaFileManager::PRODUCTS);
        if ($manual !== null) {
            return $this->toAbsoluteIfNeeded($manual, $request);
        }

        foreach ($product->getMedias() as $media) {
            $path = $this->extractMediaPath($media, MediaFileManager::PRODUCTS);
            if ($path !== null) {
                return $this->toAbsoluteIfNeeded($path, $request);
            }
        }

        return $this->toAbsoluteIfNeeded($this->defaultOgImage, $request);
    }

    /**
     * Récupère l'image principale d'une catégorie.
     */
    private function resolveCategoryImage(Category $category, Request $request): ?string
    {
        $manual = $this->manualOgImage($category->getSeoOgImage(), MediaFileManager::CATEGORIES);
        if ($manual !== null) {
            return $this->toAbsoluteIfNeeded($manual, $request);
        }

        $media = $category->getMedia();
        if ($media !== null) {
            $path = $this->extractMediaPath($media, MediaFileManager::CATEGORIES);
            if ($path !== null) {
                return $this->toAbsoluteIfNeeded($path, $request);
            }
        }

        return $this->toAbsoluteIfNeeded($this->defaultOgImage, $request);
    }

    /**
     * Récupère l'image principale d'un article de blog.
     */
    private function resolveBlogImage(Blog $blog, Request $request): ?string
    {
        $manual = $this->manualOgImage($blog->getSeoOgImage(), MediaFileManager::BLOGS);
        if ($manual !== null) {
            return $this->toAbsoluteIfNeeded($manual, $request);
        }

        foreach ($blog->getMedias() as $media) {
            $path = $this->extractMediaPath($media, MediaFileManager::BLOGS);
            if ($path !== null) {
                return $this->toAbsoluteIfNeeded($path, $request);
            }
        }

        return $this->toAbsoluteIfNeeded($this->defaultOgImage, $request);
    }

    /**
     * Image OG saisie à la main dans l'admin : URL absolue, chemin public ("/assets/…")
     * ou simple nom de fichier du dossier de l'entité. Ignorée si le fichier n'existe
     * pas, pour retomber sur la photo plutôt que d'exposer une og:image en 404.
     */
    private function manualOgImage(?string $value, string $folder): ?string
    {
        $value = \trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (\str_starts_with($value, 'http://') || \str_starts_with($value, 'https://')) {
            return $value;
        }

        $path = \str_starts_with($value, '/') ? $value : '/assets/images/' . $folder . '/' . $value;

        return \is_file(\rtrim($this->publicDir, '/') . $path) ? $path : null;
    }

    /**
     * Chemin public de l'image de partage d'un média (og:image, JSON-LD).
     *
     * Privilégie la variante JPEG `-share.jpg` (1200 px), lisible par tous les réseaux
     * sociaux contrairement au WebP ; à défaut, l'original. Les vidéos sont ignorées.
     */
    private function extractMediaPath(Media $media, string $folder): ?string
    {
        $filename = $media->getFilename();
        if (!$media->isImage() || !\is_string($filename) || $filename === '') {
            return null;
        }

        $base  = '/assets/images/' . $folder . '/';
        $share = MediaFileManager::variantName($filename, '-share', 'jpg');

        return \is_file(\rtrim($this->publicDir, '/') . $base . $share)
            ? $base . $share
            : $base . $filename;
    }

    private function toAbsoluteIfNeeded(string|array|null $pathOrUrl, Request $request): ?string
    {
        if ($pathOrUrl === null) {
            return null;
        }

        if (is_array($pathOrUrl)) {
            $pathOrUrl = array_values(array_filter(
                $pathOrUrl,
                static fn($value): bool => is_string($value) && trim($value) !== ''
            ))[0] ?? null;

            if ($pathOrUrl === null) {
                return null;
            }
        }

        $pathOrUrl = trim($pathOrUrl);

        if ($pathOrUrl === '') {
            return null;
        }

        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        $base = $this->indexabilityPolicy->getBaseUrl($request);
        if ($base === '') {
            $base = rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return $base . '/' . ltrim($pathOrUrl, '/');
    }

    /**
     * Résout la directive robots pour une page statique.
     *
     * - Si un opt-in explicite `noindex*` est passé par le contrôleur (page utilitaire),
     *   il est appliqué même si le site est globalement indexable.
     * - Sinon on utilise la politique globale (`SITE_INDEXABLE`).
     */
    private function resolveStaticRobots(?string $requested): string
    {
        if (!$this->indexabilityPolicy->isSiteIndexable()) {
            return 'noindex,nofollow';
        }

        if ($requested !== null && str_starts_with($requested, 'noindex')) {
            return $requested;
        }

        return $requested ?? $this->indexabilityPolicy->getDefaultRobots();
    }

    /**
     * Nettoie et tronque un texte pour les titres, descriptions et JSON-LD.
     */
    private function truncateText(string $text, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
    }
}
