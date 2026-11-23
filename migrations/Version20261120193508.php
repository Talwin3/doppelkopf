<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gesamtkonto Stufe 2: DDV-Tisch-Merker und Punkteanzeige am Tisch.
 */
final class Version20261120193508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tisch: DDV-Tisch-Merker und Punkteanzeige (Monat/Tischliste)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tische ADD ddv_tisch BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE tische ADD punkte_anzeige VARCHAR(10) DEFAULT \'MONAT\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tische DROP ddv_tisch');
        $this->addSql('ALTER TABLE tische DROP punkte_anzeige');
    }
}
