<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617181858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: Vorbehaltsrunde — SpielTeilnehmer bekommt vorbehaltDeklariert/Typ/SoloVariante; SpielStatus bekommt VORBEHALT';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD vorbehalt_deklariert BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD vorbehalt_typ VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD vorbehalt_solo_variante VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP vorbehalt_deklariert');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP vorbehalt_typ');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP vorbehalt_solo_variante');
    }
}
