<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot-Spielstaerke: Spalte bot_staerke fuer tisch_spieler und spiel_teilnehmer (Default anfaenger)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tisch_spieler ADD bot_staerke VARCHAR(20) DEFAULT 'anfaenger' NOT NULL");
        $this->addSql("ALTER TABLE spiel_teilnehmer ADD bot_staerke VARCHAR(20) DEFAULT 'anfaenger' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tisch_spieler DROP bot_staerke');
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP bot_staerke');
    }
}
