<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nummer des Klärungsstichs einer angemeldeten Hochzeit. Sie steuert die Ansage-
 * und Absagezeitpunkte (TSR 6.4.2): vor dem Klärungsstich keine Ansage, danach
 * verschieben sich die Fristen um (Stich-Nr − 1) Karten.
 */
final class Version20260802210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ansagezeitpunkte bei Hochzeit: spiele.hochzeit_klaerungs_stich_nr';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ADD hochzeit_klaerungs_stich_nr INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP hochzeit_klaerungs_stich_nr');
    }
}
