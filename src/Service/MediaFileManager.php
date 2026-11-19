<?php

namespace App\Service;

use App\Entity\Blog;
use App\Entity\Media;
use App\Entity\Product;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service responsable de la gestion physique des fichiers médias.
 *
 * Chaque image uploadée passe par processUploadedImage() :
 *   1. l'original est optimisé : converti en WebP (qualité 80), limité à 2000 px
 *      (orientation EXIF appliquée, métadonnées retirées) ;
 *   2. les variantes sont générées à partir de cet original :
 *      - *-thumb.webp  → 650×650 recadré (cards, miniatures)
 *      - *-medium.webp → 960 px max (fiche produit, cards blog)
 *      - *-large.webp  → 1400 px max (hero blog, sliders)
 *      - *-share.jpg   → 1200 px max, JPEG (og:image, compatible tous réseaux sociaux)
 *      - *-email.jpg   → 128×128 recadré, JPEG (emails : Outlook n'affiche pas le WebP)
 *
 * Seules les variantes réellement affichées sont générées pour chaque dossier (voir
 * ensureVariants) : logo, poster hero et logos de paiement n'en ont pas.
 * SVG, GIF (animations) et ICO sont conservés tels quels.
 * removeFile() supprime l'original et toutes ses variantes.
 */
final class MediaFileManager
{
    public const PRODUCTS   = 'products';
    public const CATEGORIES = 'categories';
    public const SLIDERS    = 'sliders';
    public const SETTINGS   = 'setting';
    public const BLOGS      = 'blogs';
    public const PAYMENT    = 'payment_methods_logos';
    public const HERO       = 'hero';

    private const ORIGINAL_MAX     = 2000;
    private const ORIGINAL_QUALITY = 80;
    // Un WebP déjà sous ce poids et ces dimensions n'est pas ré-encodé (évite de dégrader à chaque sauvegarde).
    private const WEBP_KEEP_BYTES  = 600 * 1024;

    private const THUMB_MAX  = 650;  // cards (324px × 2x retina)
    private const MEDIUM_MAX = 960;  // produit detail (660px) + blog cards (435px)
    private const LARGE_MAX  = 1400; // hero article blog (1040px × ~1.35x)
    private const SHARE_MAX  = 1200; // og:image recommandé 1200×630
    private const EMAIL_SIZE = 128;  // vignettes emails / récap commande (64px × 2x)

    private const OPTIMIZABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'bmp'];
    private const VARIANT_SUFFIXES       = ['-thumb', '-medium', '-large', '-share', '-email'];

    public function __construct(
        private Filesystem $fs,
        private string $uploadDirProducts,
        private string $uploadDirCategories,
        private string $uploadDirSliders,
        private string $uploadDirSettings,
        private string $uploadDirBlogs,
        private string $uploadDirPaymentMethod,
        private string $uploadDirVideosProducts,
        private string $uploadDirHero,
        private SluggerInterface $slugger,
    ) {}

    /**
     * Nom d'une variante : "miel-123.webp" + "-email", "jpg" → "miel-123-email.jpg".
     */
    public static function variantName(string $filename, string $suffix, string $extension = 'webp'): string
    {
        return \pathinfo($filename, \PATHINFO_FILENAME) . $suffix . '.' . $extension;
    }

    public function removeFile(Media $media): void
    {
        $filename = (string) $media->getFilename();
        if ($filename === '') {
            return;
        }

        // Vidéo uploadée : suppression directe, sans variantes.
        if ($media->isVideoUpload()) {
            $videoPath = rtrim($this->uploadDirVideosProducts, '/') . '/' . $filename;
            if ($this->fs->exists($videoPath)) {
                $this->fs->remove($videoPath);
            }
            return;
        }

        $paths = [];

        if ($media->getProduct() !== null) {
            $paths[] = rtrim($this->uploadDirProducts, '/') . '/' . $filename;
        }

        if ($media->getCategory() !== null) {
            $paths[] = rtrim($this->uploadDirCategories, '/') . '/' . $filename;
        }

        if ($media->getSliders() !== null) {
            $paths[] = rtrim($this->uploadDirSliders, '/') . '/' . $filename;
        }

        if ($media->getSetting() !== null) {
            $paths[] = rtrim($this->uploadDirSettings, '/') . '/' . $filename;
        }

        if (!$media->getBlogs()->isEmpty()) {
            $paths[] = rtrim($this->uploadDirBlogs, '/') . '/' . $filename;
        }

        if ($media->getPaymentMethod() !== null) {
            $paths[] = rtrim($this->uploadDirPaymentMethod, '/') . '/' . $filename;
        }

        if ($paths === []) {
            $paths[] = rtrim($this->uploadDirProducts, '/') . '/' . $filename;
            $paths[] = rtrim($this->uploadDirCategories, '/') . '/' . $filename;
            $paths[] = rtrim($this->uploadDirSliders, '/') . '/' . $filename;
            $paths[] = rtrim($this->uploadDirSettings, '/') . '/' . $filename;
            $paths[] = rtrim($this->uploadDirBlogs, '/') . '/' . $filename;
            $paths[] = rtrim($this->uploadDirPaymentMethod, '/') . '/' . $filename;
        }

        foreach (array_unique($paths) as $path) {
            $this->removeWithVariants($path);
        }
    }

    public function storeProductFile(UploadedFile $file, Product $product): string
    {
        $baseName = $product->getSlug() ?: $product->getTitle() ?: 'product';
        $slug = $this->slugger->slug($baseName)->lower();

        $extension = $file->guessExtension()
            ?: $file->getClientOriginalExtension()
            ?: 'bin';

        $filename = \sprintf('%s-%s.%s', $slug, \bin2hex(\random_bytes(8)), $extension);
        $dir = \rtrim($this->uploadDirProducts, '/');

        $file->move($dir, $filename);

        return $this->processUploadedImage(self::PRODUCTS, $filename);
    }

    /**
     * Stocke une vidéo produit uploadée (mp4/webm) sans génération de variantes.
     * Retourne le nom de fichier final.
     */
    public function storeProductVideoFile(UploadedFile $file, Product $product): string
    {
        $baseName = $product->getSlug() ?: $product->getTitle() ?: 'product';
        $slug = $this->slugger->slug($baseName)->lower();

        $extension = $file->guessExtension()
            ?: $file->getClientOriginalExtension()
            ?: 'mp4';

        $filename = \sprintf('%s-%s.%s', $slug, \bin2hex(\random_bytes(8)), $extension);
        $dir = \rtrim($this->uploadDirVideosProducts, '/');

        if (!$this->fs->exists($dir)) {
            $this->fs->mkdir($dir, 0755);
        }

        $file->move($dir, $filename);

        return $filename;
    }

    public function storeBlogFile(UploadedFile $file, Blog $blog): string
    {
        $baseName = $blog->getSlug() ?: $blog->getTitle() ?: 'blog';
        $slug = $this->slugger->slug($baseName)->lower();

        $extension = $file->guessExtension()
            ?: $file->getClientOriginalExtension()
            ?: 'bin';

        $filename = \sprintf('%s-%s.%s', $slug, \bin2hex(\random_bytes(8)), $extension);
        $dir = \rtrim($this->uploadDirBlogs, '/');

        $file->move($dir, $filename);

        return $this->processUploadedImage(self::BLOGS, $filename);
    }

    /**
     * Optimise une image déjà déposée dans le dossier de $target (upload EasyAdmin ou
     * storeXxxFile) puis génère ses variantes manquantes ou périmées.
     *
     * Retourne le nom de fichier final, qui change d'extension quand l'original est
     * converti en WebP (ex. "slide-123.png" → "slide-123.webp") : l'appelant doit
     * l'enregistrer en base. Idempotent : une image déjà traitée est laissée telle quelle.
     */
    public function processUploadedImage(string $target, ?string $filename): ?string
    {
        if (!\is_string($filename) || $filename === '' || !\extension_loaded('gd')) {
            return $filename;
        }

        $dir = $this->dirFor($target);
        if (!$this->fs->exists($dir . '/' . $filename)) {
            return $filename;
        }

        $filename = $this->optimizeOriginal($dir, $filename);
        $this->ensureVariants($dir, $filename, $target);

        return $filename;
    }

    /**
     * Fichier réellement présent pour une référence en base : le fichier lui-même ou, s'il
     * manque, un fichier de même nom de base dans un autre format (ex. original PNG restauré
     * depuis une sauvegarde alors que la base référence déjà le .webp).
     */
    public function resolveExistingFile(string $target, string $filename): string
    {
        $dir = $this->dirFor($target);
        if ($this->fs->exists($dir . '/' . $filename)) {
            return $filename;
        }

        $base = \pathinfo($filename, \PATHINFO_FILENAME);
        foreach (self::OPTIMIZABLE_EXTENSIONS as $extension) {
            if ($this->fs->exists($dir . '/' . $base . '.' . $extension)) {
                return $base . '.' . $extension;
            }
        }

        return $filename;
    }

    /**
     * Vérifie que l'original et toutes les variantes attendues pour ce dossier existent
     * (SVG, GIF, ICO : seul l'original est requis).
     */
    public function hasExpectedVariants(string $target, string $filename): bool
    {
        $dir = $this->dirFor($target);
        if (!$this->fs->exists($dir . '/' . $filename)) {
            return false;
        }

        $extension = \strtolower(\pathinfo($filename, \PATHINFO_EXTENSION));
        if (!\in_array($extension, self::OPTIMIZABLE_EXTENSIONS, true)) {
            return true;
        }

        foreach (self::variantsFor($target) as [$suffix, $variantExt]) {
            if (!$this->fs->exists($dir . '/' . self::variantName($filename, $suffix, $variantExt))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indique si processUploadedImage() ré-encoderait l'original (utilisé par le dry-run
     * de app:optimize-images). Ne tient pas compte des variantes.
     */
    public function needsOptimization(string $target, string $filename): bool
    {
        $extension = \strtolower(\pathinfo($filename, \PATHINFO_EXTENSION));
        $source    = $this->dirFor($target) . '/' . $filename;
        if (!\in_array($extension, self::OPTIMIZABLE_EXTENSIONS, true) || !$this->fs->exists($source)) {
            return false;
        }

        $size = @\getimagesize($source);

        return $size !== false
            && ($extension !== 'webp'
                || \max($size[0], $size[1]) > self::ORIGINAL_MAX
                || (int) \filesize($source) > self::WEBP_KEEP_BYTES);
    }

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    private function dirFor(string $target): string
    {
        return \rtrim(match ($target) {
            self::PRODUCTS   => $this->uploadDirProducts,
            self::CATEGORIES => $this->uploadDirCategories,
            self::SLIDERS    => $this->uploadDirSliders,
            self::SETTINGS   => $this->uploadDirSettings,
            self::BLOGS      => $this->uploadDirBlogs,
            self::PAYMENT    => $this->uploadDirPaymentMethod,
            self::HERO       => $this->uploadDirHero,
            default          => throw new \InvalidArgumentException(\sprintf('Dossier média inconnu : "%s".', $target)),
        }, '/');
    }

    /**
     * Convertit l'original en WebP redimensionné. Retourne le nouveau nom de fichier,
     * ou le nom inchangé si l'image est déjà optimisée, non convertible, ou en cas d'erreur.
     */
    private function optimizeOriginal(string $dir, string $filename): string
    {
        $extension = \strtolower(\pathinfo($filename, \PATHINFO_EXTENSION));
        if (!\in_array($extension, self::OPTIMIZABLE_EXTENSIONS, true)) {
            return $filename;
        }

        $source = $dir . '/' . $filename;
        $size   = @\getimagesize($source);
        if ($size === false) {
            return $filename;
        }

        $needsResize = \max($size[0], $size[1]) > self::ORIGINAL_MAX;
        $sourceBytes = (int) \filesize($source);

        if ($extension === 'webp' && !$needsResize && $sourceBytes <= self::WEBP_KEEP_BYTES) {
            return $filename;
        }

        try {
            $image = (new ImageManager(new Driver()))->decode($source);
            if ($needsResize) {
                $image->scaleDown(self::ORIGINAL_MAX, self::ORIGINAL_MAX);
            }
            $encoded = $image->encode(new WebpEncoder(quality: self::ORIGINAL_QUALITY, strip: true))->toString();
        } catch (\Throwable) {
            return $filename;
        }

        // Déjà en WebP aux bonnes dimensions : on ne garde le ré-encodage que s'il fait gagner du poids.
        if ($extension === 'webp' && !$needsResize && \strlen($encoded) >= $sourceBytes) {
            return $filename;
        }

        $base = \pathinfo($filename, \PATHINFO_FILENAME);
        $target = $base . '.webp';
        if ($extension !== 'webp' && $this->fs->exists($dir . '/' . $target)) {
            $target = $base . '-' . \bin2hex(\random_bytes(3)) . '.webp';
        }

        $this->fs->dumpFile($dir . '/' . $target, $encoded);

        if ($target !== $filename) {
            $this->fs->remove($source);
        }

        return $target;
    }

    /**
     * Génère les variantes absentes ou plus anciennes que l'original.
     */
    private function ensureVariants(string $dir, string $filename, string $target): void
    {
        $source = $dir . '/' . $filename;
        $extension = \strtolower(\pathinfo($filename, \PATHINFO_EXTENSION));
        if (!\in_array($extension, self::OPTIMIZABLE_EXTENSIONS, true)) {
            return;
        }

        $renders = [
            '-thumb'  => fn (ImageInterface $i) => $i->cover(self::THUMB_MAX, self::THUMB_MAX)->encode(new WebpEncoder(strip: true)),
            '-medium' => fn (ImageInterface $i) => $i->scaleDown(self::MEDIUM_MAX, self::MEDIUM_MAX)->encode(new WebpEncoder(strip: true)),
            '-large'  => fn (ImageInterface $i) => $i->scaleDown(self::LARGE_MAX, self::LARGE_MAX)->encode(new WebpEncoder(strip: true)),
            '-share'  => fn (ImageInterface $i) => $i->scaleDown(self::SHARE_MAX, self::SHARE_MAX)->encode(new JpegEncoder(quality: 82, progressive: true, strip: true)),
            '-email'  => fn (ImageInterface $i) => $i->cover(self::EMAIL_SIZE, self::EMAIL_SIZE)->encode(new JpegEncoder(quality: 80, strip: true)),
        ];

        $sourceMtime = (int) \filemtime($source);
        $image = null;

        foreach (self::variantsFor($target) as [$suffix, $variantExt]) {
            $path = $dir . '/' . self::variantName($filename, $suffix, $variantExt);
            if ($this->fs->exists($path) && (int) \filemtime($path) >= $sourceMtime) {
                continue;
            }

            try {
                $image ??= (new ImageManager(new Driver()))->decode($source);
                $this->fs->dumpFile($path, $renders[$suffix](clone $image)->toString());
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Variantes générées pour chaque dossier, sous forme [suffixe, extension].
     *
     * @return list<array{string, string}>
     */
    private static function variantsFor(string $target): array
    {
        $webp = [['-thumb', 'webp'], ['-medium', 'webp'], ['-large', 'webp']];

        return match ($target) {
            self::PRODUCTS                => [...$webp, ['-share', 'jpg'], ['-email', 'jpg']],
            self::BLOGS, self::CATEGORIES => [...$webp, ['-share', 'jpg']],
            self::SLIDERS                 => $webp,
            default                       => [],
        };
    }

    /**
     * Supprime un fichier et toutes ses variantes.
     */
    private function removeWithVariants(string $path): void
    {
        $info = \pathinfo($path);
        $dir  = $info['dirname'];
        $base = $info['filename'];
        $ext  = $info['extension'] ?? '';

        // Supprimer le fichier original s'il existe
        if ($this->fs->exists($path)) {
            $this->fs->remove($path);
        }

        // Supprimer les variantes dans tous les cas (même si l'original est absent)
        foreach (self::VARIANT_SUFFIXES as $suffix) {
            foreach (array_unique(['webp', 'jpg', $ext]) as $varExt) {
                if ($varExt === '') {
                    continue;
                }
                $variant = $dir . '/' . $base . $suffix . '.' . $varExt;
                if ($this->fs->exists($variant)) {
                    $this->fs->remove($variant);
                }
            }
        }
    }
}
