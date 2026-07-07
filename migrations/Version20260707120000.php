<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replays überleben die Tisch-Löschung: spiele.tisch_id wird nullable und das
 * Löschen eines Tischs setzt es auf NULL (statt die Spiele mitzulöschen).
 * Zusätzlich hält jedes Spiel einen Snapshot der Regel-Einstellungen, damit
 * Auswertung/Replay ohne Tisch funktionieren.
 */
final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spiele vom Tisch abloesen: tisch_id nullable + ON DELETE SET NULL, Regelwerk-Snapshot pro Spiel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ADD regel_einstellungen_snapshot JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE spiele ALTER COLUMN tisch_id DROP NOT NULL');
        $this->addSql('ALTER TABLE spiele DROP CONSTRAINT fk_39043e3d67a0f809');
        $this->addSql('ALTER TABLE spiele ADD CONSTRAINT fk_39043e3d67a0f809 FOREIGN KEY (tisch_id) REFERENCES tische (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Verwaiste Spiele (Tisch bereits gelöscht) müssten vor dem Zurücksetzen
        // entfernt werden, sonst schlägt NOT NULL fehl.
        $this->addSql('DELETE FROM spiele WHERE tisch_id IS NULL');

        $this->addSql('ALTER TABLE spiele DROP CONSTRAINT fk_39043e3d67a0f809');
        $this->addSql('ALTER TABLE spiele ADD CONSTRAINT fk_39043e3d67a0f809 FOREIGN KEY (tisch_id) REFERENCES tische (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE spiele ALTER COLUMN tisch_id SET NOT NULL');

        $this->addSql('ALTER TABLE spiele DROP regel_einstellungen_snapshot');
    }
}
