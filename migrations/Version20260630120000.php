<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persönliche Schnell-Chatnachrichten: Spalte chat_phrasen (JSON) fuer users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD chat_phrasen JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP chat_phrasen');
    }
}
