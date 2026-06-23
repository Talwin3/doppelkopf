<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot-Personalisierung: Spalte bot_name fuer tisch_spieler und spiel_teilnehmer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tisch_spieler ADD bot_name VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD bot_name VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tisch_spieler DROP bot_name');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP bot_name');
    }
}
