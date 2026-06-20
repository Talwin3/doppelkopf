<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kartendeck-Präferenz: Altwert FRANZOESISCH → BELLOT, Spalten-Default anpassen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE users SET kartenbild_praeferenz = 'BELLOT' WHERE kartenbild_praeferenz = 'FRANZOESISCH'");
        $this->addSql("ALTER TABLE users ALTER COLUMN kartenbild_praeferenz SET DEFAULT 'BELLOT'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ALTER COLUMN kartenbild_praeferenz SET DEFAULT 'FRANZOESISCH'");
        $this->addSql("UPDATE users SET kartenbild_praeferenz = 'FRANZOESISCH' WHERE kartenbild_praeferenz = 'BELLOT'");
    }
}
