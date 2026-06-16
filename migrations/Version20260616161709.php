<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616161709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE spiel_ansagen (id UUID NOT NULL, sitzplatz INT NOT NULL, ansage_typ VARCHAR(15) NOT NULL, stich_nr_bei_ansage INT NOT NULL, karten_noch_in_hand INT NOT NULL, gemacht_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, spiel_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DBC90E753D4C6B07 ON spiel_ansagen (spiel_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_ansage_typ_sitzplatz ON spiel_ansagen (spiel_id, sitzplatz, ansage_typ)');
        $this->addSql('ALTER TABLE spiel_ansagen ADD CONSTRAINT FK_DBC90E753D4C6B07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE spiel_ansagen DROP CONSTRAINT FK_DBC90E753D4C6B07');
        $this->addSql('DROP TABLE spiel_ansagen');
    }
}
