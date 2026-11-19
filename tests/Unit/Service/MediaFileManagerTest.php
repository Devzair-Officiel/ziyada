<?php

namespace App\Tests\Unit\Service;

use App\Service\MediaFileManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[RequiresPhpExtension('gd')]
final class MediaFileManagerTest extends TestCase
{
    private Filesystem $fs;
    private string $root;
    private MediaFileManager $manager;

    protected function setUp(): void
    {
        $this->fs   = new Filesystem();
        $this->root = \sys_get_temp_dir() . '/media-file-manager-' . \bin2hex(\random_bytes(4));

        $dirs = [];
        foreach (['products', 'categories', 'sliders', 'setting', 'blogs', 'payment', 'videos', 'hero'] as $name) {
            $dirs[$name] = $this->root . '/' . $name;
            $this->fs->mkdir($dirs[$name]);
        }

        $this->manager = new MediaFileManager(
            $this->fs,
            $dirs['products'],
            $dirs['categories'],
            $dirs['sliders'],
            $dirs['setting'],
            $dirs['blogs'],
            $dirs['payment'],
            $dirs['videos'],
            $dirs['hero'],
            new AsciiSlugger(),
        );
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->root);
    }

    public function testOriginalPngConvertiEnWebpRedimensionne(): void
    {
        $this->createPng($this->root . '/sliders/slide-1.png', 2600, 1200);

        $filename = $this->manager->processUploadedImage(MediaFileManager::SLIDERS, 'slide-1.png');

        self::assertSame('slide-1.webp', $filename);
        self::assertFileDoesNotExist($this->root . '/sliders/slide-1.png');

        [$width, $height, $type] = \getimagesize($this->root . '/sliders/slide-1.webp');
        self::assertSame(\IMAGETYPE_WEBP, $type);
        self::assertSame(2000, $width);
        self::assertLessThanOrEqual(2000, $height);

        foreach (['-thumb', '-medium', '-large'] as $suffix) {
            self::assertFileExists($this->root . '/sliders/slide-1' . $suffix . '.webp');
        }
        // Les JPEG de partage / email sont réservés aux produits, blogs et catégories.
        self::assertFileDoesNotExist($this->root . '/sliders/slide-1-share.jpg');
    }

    public function testProduitGenereVariantesJpegPartageEtEmail(): void
    {
        $this->createPng($this->root . '/products/miel.png', 800, 600);

        $filename = $this->manager->processUploadedImage(MediaFileManager::PRODUCTS, 'miel.png');

        self::assertSame('miel.webp', $filename);
        self::assertSame(\IMAGETYPE_JPEG, \getimagesize($this->root . '/products/miel-share.jpg')[2]);

        [$width, $height, $type] = \getimagesize($this->root . '/products/miel-email.jpg');
        self::assertSame([128, 128, \IMAGETYPE_JPEG], [$width, $height, $type]);
    }

    public function testWebpDejaOptimiseLaisseIntact(): void
    {
        $this->createPng($this->root . '/products/miel.png', 800, 600);
        $filename = $this->manager->processUploadedImage(MediaFileManager::PRODUCTS, 'miel.png');
        $hash = \md5_file($this->root . '/products/' . $filename);

        self::assertFalse($this->manager->needsOptimization(MediaFileManager::PRODUCTS, $filename));
        self::assertSame($filename, $this->manager->processUploadedImage(MediaFileManager::PRODUCTS, $filename));
        self::assertSame($hash, \md5_file($this->root . '/products/' . $filename));
    }

    public function testSvgEtFichierAbsentConserves(): void
    {
        $this->fs->dumpFile($this->root . '/payment/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        self::assertSame('logo.svg', $this->manager->processUploadedImage(MediaFileManager::PAYMENT, 'logo.svg'));
        self::assertFileExists($this->root . '/payment/logo.svg');

        self::assertSame('absent.png', $this->manager->processUploadedImage(MediaFileManager::PAYMENT, 'absent.png'));
        self::assertNull($this->manager->processUploadedImage(MediaFileManager::PAYMENT, null));
    }

    public function testLogoSansVariantes(): void
    {
        $this->createPng($this->root . '/setting/logo.png', 400, 120);

        self::assertSame('logo.webp', $this->manager->processUploadedImage(MediaFileManager::SETTINGS, 'logo.png'));
        self::assertSame(['logo.webp'], \array_values(\array_diff(\scandir($this->root . '/setting'), ['.', '..'])));
    }

    public function testOriginalRestaureDansUnAutreFormatEstRetrouve(): void
    {
        $this->createPng($this->root . '/products/miel.png', 800, 600);

        self::assertSame('miel.png', $this->manager->resolveExistingFile(MediaFileManager::PRODUCTS, 'miel.webp'));
        self::assertSame('absent.webp', $this->manager->resolveExistingFile(MediaFileManager::PRODUCTS, 'absent.webp'));
    }

    public function testHasExpectedVariants(): void
    {
        $this->createPng($this->root . '/products/miel.png', 800, 600);
        $filename = $this->manager->processUploadedImage(MediaFileManager::PRODUCTS, 'miel.png');
        self::assertTrue($this->manager->hasExpectedVariants(MediaFileManager::PRODUCTS, $filename));

        $this->fs->remove($this->root . '/products/miel-share.jpg');
        self::assertFalse($this->manager->hasExpectedVariants(MediaFileManager::PRODUCTS, $filename));
        self::assertFalse($this->manager->hasExpectedVariants(MediaFileManager::PRODUCTS, 'absent.webp'));
    }

    public function testVariantName(): void
    {
        self::assertSame('miel.v2-email.jpg', MediaFileManager::variantName('miel.v2.webp', '-email', 'jpg'));
        self::assertSame('miel-thumb.webp', MediaFileManager::variantName('miel.png', '-thumb'));
    }

    private function createPng(string $path, int $width, int $height): void
    {
        $image = \imagecreatetruecolor($width, $height);
        \imagefill($image, 0, 0, \imagecolorallocate($image, 230, 170, 40));
        \imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), \imagecolorallocate($image, 120, 60, 10));
        \imagepng($image, $path);
    }
}
