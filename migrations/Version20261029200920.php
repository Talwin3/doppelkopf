<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema-Drift in users bereinigen: Der Default von chat_phrasen diente nur dem
 * Befuellen bestehender Zeilen beim Anlegen (Version20260630120000), die Entity
 * setzt den Wert selbst. Der DC2Type-Kommentar ist ein Ueberbleibsel aus DBAL 3.
 */
final class Version20261029200920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: Default von chat_phrasen und DC2Type-Kommentar von nutzername_geaendert_am entfernen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ALTER chat_phrasen DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN users.nutzername_geaendert_am IS \'\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ALTER chat_phrasen SET DEFAULT \'[]\'');
        $this->addSql('COMMENT ON COLUMN users.nutzername_geaendert_am IS \'(DC2Type:datetime_immutable)\'');
    }
}
