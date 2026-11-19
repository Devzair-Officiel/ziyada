<?php

namespace App\EventListener;

use App\Entity\Media;
use App\Service\MediaFileManager;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;

/**
 * Écoute les événements Doctrine sur l'entité Media.
 *
 * - postRemove : supprime physiquement le fichier du disque
 *   lorsque l'entité Media est supprimée de la base de données
 *   (suppression directe ou via cascade/orphanRemoval).
 *
 * - preUpdate : supprime l'ancien fichier du disque lorsqu'un
 *   nouveau fichier remplace l'ancien (changement de filename).
 *
 * Objectif : éviter les fichiers orphelins sur le disque.
 */

#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class MediaFileCleanupListener
{
    public function __construct(private MediaFileManager $files) {}

    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Media) {
            return;
        }

        $this->files->removeFile($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Media) {
            return;
        }

        $filenameChanged = $args->hasChangedField('filename');
        $typeChanged     = $args->hasChangedField('type');

        if (!$filenameChanged && !$typeChanged) {
            return;
        }

        // Fichier physique concerné : celui qui existait avant l'update
        $oldFilename = $filenameChanged
            ? $args->getOldValue('filename')
            : $entity->getFilename();

        if (!is_string($oldFilename) || $oldFilename === '') {
            return;
        }

        // Conversion de format du même fichier (x.png → x.webp, cf. MediaFileManager::processUploadedImage) :
        // l'ancien original est déjà supprimé et les variantes, qui portent le même nom de base,
        // appartiennent désormais au nouveau fichier. Les supprimer casserait l'affichage.
        $newFilename = $entity->getFilename();
        if ($filenameChanged && is_string($newFilename)
            && pathinfo($oldFilename, PATHINFO_FILENAME) === pathinfo($newFilename, PATHINFO_FILENAME)) {
            return;
        }

        $oldType = $typeChanged
            ? (string) $args->getOldValue('type')
            : $entity->getType();

        // On supprime l'ancien fichier (avant remplacement) en préservant le type d'origine,
        // sinon on chercherait la vidéo dans le dossier des images (ou vice-versa).
        $tmp = new Media();
        $tmp->setType($oldType);
        $tmp->setFilename($oldFilename);
        $tmp->setProduct($entity->getProduct());
        $tmp->setCategory($entity->getCategory());
        $tmp->setSliders($entity->getSliders());
        $tmp->setSetting($entity->getSetting());

        $this->files->removeFile($tmp);
    }
}
