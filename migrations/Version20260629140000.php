<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event-Log: Spalte von_bot_vertreten fuer spiel_teilnehmer (Default false)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD von_bot_vertreten BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP von_bot_vertreten');
    }
}
