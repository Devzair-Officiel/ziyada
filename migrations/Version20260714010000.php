<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la mise en pause qualité sur les produits : label court + motif détaillé
 * affichés dans un ruban sur la carte et une modale explicative.
 */
final class Version20260714010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quality_hold_label and quality_hold_reason columns to product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD quality_hold_label VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD quality_hold_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP COLUMN quality_hold_reason');
        $this->addSql('ALTER TABLE product DROP COLUMN quality_hold_label');
    }
}
