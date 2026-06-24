<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avatare: Spalten avatar_stil und avatar_seed fuer users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD avatar_stil VARCHAR(20) DEFAULT 'lorelei' NOT NULL");
        $this->addSql("ALTER TABLE users ADD avatar_seed VARCHAR(32) DEFAULT '' NOT NULL");
        // Bestand: stabilen Seed aus den ersten 16 Zeichen der User-ID ableiten.
        $this->addSql("UPDATE users SET avatar_seed = SUBSTRING(CAST(id AS TEXT) FROM 1 FOR 16) WHERE avatar_seed = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP avatar_stil');
        $this->addSql('ALTER TABLE users DROP avatar_seed');
    }
}
