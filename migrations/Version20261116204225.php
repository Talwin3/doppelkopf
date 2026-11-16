<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gesamtkonto Stufe 1: Wertungskategorie und globale Wertung je Spiel,
 * Bot-Kennung je Teilnehmer (Tischliste), Zähler verpasster Züge (Platzverlust). Alte Spiele bleiben ohne Kategorie
 * und ungewertet (es gibt noch keine Produktivdaten).
 */
final class Version20261116204225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gesamtkonto: spiele.kategorie, spiele.global_gewertet, spiel_teilnehmer.bot_kennung, spiel_teilnehmer.verpasste_zuege_in_folge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD bot_kennung UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD verpasste_zuege_in_folge INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE spiele ADD kategorie VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE spiele ADD global_gewertet BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE INDEX idx_spiele_bestenliste ON spiele (kategorie, global_gewertet, beendet_am)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP bot_kennung');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP verpasste_zuege_in_folge');
        $this->addSql('DROP INDEX idx_spiele_bestenliste');
        $this->addSql('ALTER TABLE spiele DROP kategorie');
        $this->addSql('ALTER TABLE spiele DROP global_gewertet');
    }
}
