<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Berechtigungen: Spalte steuerungs_modus fuer tische (Default ALLE = bisheriges Verhalten)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tische ADD steuerungs_modus VARCHAR(20) DEFAULT 'ALLE' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tische DROP steuerungs_modus');
    }
}
