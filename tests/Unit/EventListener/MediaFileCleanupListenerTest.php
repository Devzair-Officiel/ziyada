<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Media;
use App\Entity\Product;
use App\EventListener\MediaFileCleanupListener;
use App\Service\MediaFileManager;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Ce que l'on teste :
 *  - Remplacement par un autre fichier → ancien original + variantes supprimés
 *  - Conversion de format du même fichier (x.png → x.webp) → variantes conservées
 */
class MediaFileCleanupListenerTest extends TestCase
{
    private Filesystem $fs;
    private string $dir;
    private MediaFileCleanupListener $listener;

    protected function setUp(): void
    {
        $this->fs  = new Filesystem();
        $this->dir = \sys_get_temp_dir() . '/media-cleanup-' . \bin2hex(\random_bytes(4));
        $this->fs->mkdir($this->dir);

        $d = $this->dir;
        $this->listener = new MediaFileCleanupListener(
            new MediaFileManager($this->fs, $d, $d, $d, $d, $d, $d, $d, $d, new AsciiSlugger()),
        );
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    public function testRemplacementSupprimeAncienFichierEtVariantes(): void
    {
        $this->touch('ancien.webp', 'ancien-thumb.webp', 'ancien-share.jpg', 'nouveau.webp');

        $this->listener->preUpdate($this->makeArgs($this->makeMedia('nouveau.webp'), 'ancien.webp'));

        self::assertFileDoesNotExist($this->dir . '/ancien.webp');
        self::assertFileDoesNotExist($this->dir . '/ancien-thumb.webp');
        self::assertFileDoesNotExist($this->dir . '/ancien-share.jpg');
        self::assertFileExists($this->dir . '/nouveau.webp');
    }

    public function testConversionDeFormatConserveLesVariantes(): void
    {
        $this->touch('miel.webp', 'miel-thumb.webp', 'miel-medium.webp', 'miel-share.jpg', 'miel-email.jpg');

        $this->listener->preUpdate($this->makeArgs($this->makeMedia('miel.webp'), 'miel.png'));

        foreach (['miel.webp', 'miel-thumb.webp', 'miel-medium.webp', 'miel-share.jpg', 'miel-email.jpg'] as $file) {
            self::assertFileExists($this->dir . '/' . $file);
        }
    }

    private function makeMedia(string $filename): Media
    {
        return (new Media())->setType('image')->setFilename($filename)->setProduct(new Product());
    }

    private function makeArgs(Media $media, string $oldFilename): PreUpdateEventArgs
    {
        $args = $this->createStub(PreUpdateEventArgs::class);
        $args->method('getObject')->willReturn($media);
        $args->method('hasChangedField')->willReturnCallback(static fn (string $field) => $field === 'filename');
        $args->method('getOldValue')->willReturn($oldFilename);

        return $args;
    }

    private function touch(string ...$files): void
    {
        foreach ($files as $file) {
            $this->fs->dumpFile($this->dir . '/' . $file, 'x');
        }
    }
}
