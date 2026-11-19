<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le support vidéo sur les médias : type (image|video) et video_url (embed YouTube/Vimeo).
 * Les lignes existantes prennent 'image' par défaut afin de préserver la galerie actuelle.
 */
final class Version20260714000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add type and video_url columns to media (image/video support)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media ADD type VARCHAR(20) NOT NULL DEFAULT 'image'");
        $this->addSql('ALTER TABLE media ADD video_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP COLUMN video_url');
        $this->addSql('ALTER TABLE media DROP COLUMN type');
    }
}
