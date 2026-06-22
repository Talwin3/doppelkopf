<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kartendeck-Default fuer neue Nutzer: BELLOT -> KNOLL (bestehende Praeferenzen bleiben unveraendert)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ALTER COLUMN kartenbild_praeferenz SET DEFAULT 'KNOLL'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ALTER COLUMN kartenbild_praeferenz SET DEFAULT 'BELLOT'");
    }
}
