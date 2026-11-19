<?php

namespace App\Command;

use App\Entity\Media;
use App\Entity\Setting;
use App\Entity\Sliders;
use App\Service\MediaFileManager;
use App\Storefront\StorefrontGlobalsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applique aux images déjà en ligne le traitement fait désormais à l'upload :
 * original converti en WebP optimisé (2000 px max) + variantes manquantes.
 *
 * Part des références en base (Media, slider mobile, poster hero) et non des
 * dossiers : quand l'original change d'extension (.png → .webp), le nom est mis à
 * jour en base, ainsi que les URLs d'image figées dans les lignes de commande
 * (order_details.product_image_url), redirigées vers la vignette JPEG -email.
 *
 * À lancer une fois après déploiement (d'abord avec --dry-run), sur chaque environnement.
 */
#[AsCommand(
    name: 'app:optimize-images',
    description: 'Convertit les images uploadées en WebP optimisé et génère leurs variantes.',
)]
final class OptimizeImagesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileManager $files,
        private readonly StorefrontGlobalsProvider $globalsProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les originaux à convertir sans rien modifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!\extension_loaded('gd')) {
            $io->error('L\'extension GD n\'est pas disponible.');
            return Command::FAILURE;
        }

        $converted = 0;
        $renamed   = 0;

        // [dossier, nom actuel, setter du nouveau nom]
        $references = [];

        foreach ($this->em->getRepository(Media::class)->findAll() as $media) {
            $target = $this->targetFor($media);
            if ($target !== null && $media->isImage() && $media->getFilename()) {
                $references[] = [$target, $media->getFilename(), $media->setFilename(...)];
            }
        }

        foreach ($this->em->getRepository(Sliders::class)->findAll() as $slider) {
            if ($slider->getMediaSliderMobile()) {
                $references[] = [MediaFileManager::SLIDERS, $slider->getMediaSliderMobile(), $slider->setMediaSliderMobile(...)];
            }
        }

        foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
            if ($setting->getHeroPosterFilename()) {
                $references[] = [MediaFileManager::HERO, $setting->getHeroPosterFilename(), $setting->setHeroPosterFilename(...)];
            }
        }

        foreach ($references as [$target, $storedFilename, $setFilename]) {
            $filename = $this->files->resolveExistingFile($target, $storedFilename);

            if ($dryRun) {
                if ($this->files->needsOptimization($target, $filename)) {
                    $io->writeln(\sprintf('<info>À convertir</info> %s/%s', $target, $filename));
                    ++$converted;
                }
                continue;
            }

            $wasOptimizable = $this->files->needsOptimization($target, $filename);
            $newFilename = $this->files->processUploadedImage($target, $filename);

            if ($wasOptimizable && !$this->files->needsOptimization($target, (string) $newFilename)) {
                ++$converted;
            }

            if ($newFilename !== $filename) {
                $io->writeln(\sprintf('<info>Converti</info> %s/%s → %s', $target, $filename, $newFilename));
            }

            if ($newFilename !== $storedFilename) {
                $setFilename($newFilename);
                ++$renamed;
            }

            if (!$this->files->hasExpectedVariants($target, (string) $newFilename)) {
                $io->warning(\sprintf('Original ou variantes manquants pour %s/%s', $target, $newFilename));
            }

            // La vignette -email n'existe que pour les originaux WebP (pas pour un SVG/GIF).
            if ($target === MediaFileManager::PRODUCTS && \str_ends_with(\strtolower((string) $newFilename), '.webp')) {
                $this->repointOrderSnapshots($storedFilename, (string) $newFilename);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
            $this->globalsProvider->invalidateSetting();
        }

        $io->success(\sprintf(
            $dryRun
                ? '%d image(s) à convertir sur %d référencée(s). [dry-run, rien n\'a été modifié]'
                : '%d image(s) optimisée(s) dont %d renommée(s) en .webp, sur %d référencée(s). Variantes à jour.',
            ...($dryRun ? [$converted, \count($references)] : [$converted, $renamed, \count($references)]),
        ));

        return Command::SUCCESS;
    }

    private function targetFor(Media $media): ?string
    {
        return match (true) {
            $media->getProduct() !== null       => MediaFileManager::PRODUCTS,
            $media->getCategory() !== null      => MediaFileManager::CATEGORIES,
            $media->getSliders() !== null       => MediaFileManager::SLIDERS,
            $media->getSetting() !== null       => MediaFileManager::SETTINGS,
            !$media->getBlogs()->isEmpty()      => MediaFileManager::BLOGS,
            $media->getPaymentMethod() !== null => MediaFileManager::PAYMENT,
            default                             => null,
        };
    }

    /**
     * Les commandes passées gardent l'URL absolue de l'image au moment de l'achat
     * (emails, espace client, admin) : on la fait pointer vers la vignette JPEG.
     */
    private function repointOrderSnapshots(string $oldFilename, string $newFilename): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE order_details SET product_image_url = REPLACE(product_image_url, :old, :new) WHERE product_image_url LIKE :like',
            [
                'old'  => '/assets/images/products/' . $oldFilename,
                'new'  => '/assets/images/products/' . MediaFileManager::variantName($newFilename, '-email', 'jpg'),
                'like' => '%/assets/images/products/' . \addcslashes($oldFilename, '%_\\'),
            ],
        );
    }
}
