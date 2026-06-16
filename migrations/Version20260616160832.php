<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616160832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1: Spiel, SpielTeilnehmer, GespielteKarte (Spieltisch-Fundament)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE gespielte_karten (id UUID NOT NULL, sitzplatz INT NOT NULL, stich_nr INT NOT NULL, position_im_stich INT NOT NULL, karte_id VARCHAR(30) NOT NULL, gespielt_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, spiel_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2C15D8D23D4C6B07 ON gespielte_karten (spiel_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_stich_position ON gespielte_karten (spiel_id, stich_nr, position_im_stich)');
        $this->addSql('CREATE UNIQUE INDEX uq_karte_einmal_gespielt ON gespielte_karten (spiel_id, sitzplatz, karte_id)');
        $this->addSql('CREATE TABLE spiel_teilnehmer (id UUID NOT NULL, sitzplatz INT NOT NULL, startkarten_ids JSON NOT NULL, team VARCHAR(10) DEFAULT NULL, ist_bot BOOLEAN DEFAULT false NOT NULL, gewonnen BOOLEAN DEFAULT NULL, punkte_delta INT DEFAULT NULL, spiel_id UUID NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FAD4652F3D4C6B07 ON spiel_teilnehmer (spiel_id)');
        $this->addSql('CREATE INDEX IDX_FAD4652FA76ED395 ON spiel_teilnehmer (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_spiel_sitzplatz ON spiel_teilnehmer (spiel_id, sitzplatz)');
        $this->addSql('CREATE TABLE spiele (id UUID NOT NULL, variante VARCHAR(20) NOT NULL, status VARCHAR(10) NOT NULL, aktueller_spieler_sitzplatz INT NOT NULL, aktueller_stich_nr INT NOT NULL, hochzeit_aufgeloest BOOLEAN DEFAULT false NOT NULL, gestartet_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, beendet_am TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tisch_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_39043E3D67A0F809 ON spiele (tisch_id)');
        $this->addSql('ALTER TABLE gespielte_karten ADD CONSTRAINT FK_2C15D8D23D4C6B07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD CONSTRAINT FK_FAD4652F3D4C6B07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD CONSTRAINT FK_FAD4652FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE spiele ADD CONSTRAINT FK_39043E3D67A0F809 FOREIGN KEY (tisch_id) REFERENCES tische (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gespielte_karten DROP CONSTRAINT FK_2C15D8D23D4C6B07');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP CONSTRAINT FK_FAD4652F3D4C6B07');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP CONSTRAINT FK_FAD4652FA76ED395');
        $this->addSql('ALTER TABLE spiele DROP CONSTRAINT FK_39043E3D67A0F809');
        $this->addSql('DROP TABLE gespielte_karten');
        $this->addSql('DROP TABLE spiel_teilnehmer');
        $this->addSql('DROP TABLE spiele');
    }
}
