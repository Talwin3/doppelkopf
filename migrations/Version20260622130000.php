<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spiel: Spalte wertung_details (JSON) fuer die detaillierte Abrechnung';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ADD wertung_details JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP wertung_details');
    }
}
