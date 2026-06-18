<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3: Armut — neue Felder auf spiele (armut_spieler/annehmer/tausch) und spiel_teilnehmer (armut_antwort)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ADD armut_spieler_sitzplatz INT DEFAULT NULL');
        $this->addSql('ALTER TABLE spiele ADD armut_annehmer_sitzplatz INT DEFAULT NULL');
        $this->addSql('ALTER TABLE spiele ADD armut_tausch_karten_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD armut_antwort BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP armut_spieler_sitzplatz');
        $this->addSql('ALTER TABLE spiele DROP armut_annehmer_sitzplatz');
        $this->addSql('ALTER TABLE spiele DROP armut_tausch_karten_ids');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP armut_antwort');
    }
}
