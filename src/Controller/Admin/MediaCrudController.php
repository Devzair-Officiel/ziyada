<?php

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Service\MediaFileManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

/**
 * Contrôleur EasyAdmin pour la gestion CRUD des médias (images) associés aux produits et catégories.
 */
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MediaCrudController extends AbstractCrudController
{
    public function __construct(private readonly MediaFileManager $files) {}

    public static function getEntityFqcn(): string
    {
        return Media::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            ImageField::new('filename')
                ->setBasePath('/assets/images/products')
                ->setUploadDir('public/assets/images/products')
                // ->setUploadedFileNamePattern('[slug].[extension]') // ou autre
                ->setRequired($pageName === 'new'),

            AssociationField::new('product')->setRequired(false),
            AssociationField::new('category')->setRequired(false),

            IntegerField::new('position'),
            BooleanField::new('isCover'),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->processImage($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->processImage($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    /** Conversion WebP de l'original + variantes produit, avant le flush (le nom peut changer). */
    private function processImage(Media $media): void
    {
        if ($media->isImage()) {
            $media->setFilename($this->files->processUploadedImage(MediaFileManager::PRODUCTS, $media->getFilename()));
        }
    }
}
