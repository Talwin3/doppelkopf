<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: nutzername_geaendert_am (Cooldown) + profil_oeffentlich (Datenschutz)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD nutzername_geaendert_am TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD profil_oeffentlich BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('COMMENT ON COLUMN users.nutzername_geaendert_am IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP nutzername_geaendert_am');
        $this->addSql('ALTER TABLE users DROP profil_oeffentlich');
    }
}
