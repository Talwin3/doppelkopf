<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat-Event-Log: Spalte typ fuer chat_nachrichten (Default SPIELER = bisheriges Verhalten)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE chat_nachrichten ADD typ VARCHAR(10) DEFAULT 'SPIELER' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_nachrichten DROP typ');
    }
}
