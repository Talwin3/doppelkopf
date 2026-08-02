<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabelle für angeforderte Passwort-Zurücksetzungen (symfonycasts/reset-password-bundle).
 * Der Token liegt nur gehasht vor; offene Anfragen werden mit dem Nutzer gelöscht.
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passwort-Reset-Anfragen: Tabelle passwort_reset_anfragen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE passwort_reset_anfragen (id UUID NOT NULL, user_id UUID NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8E00C6E5A76ED395 ON passwort_reset_anfragen (user_id)');
        $this->addSql('CREATE INDEX idx_reset_selector ON passwort_reset_anfragen (selector)');
        $this->addSql('ALTER TABLE passwort_reset_anfragen ADD CONSTRAINT FK_8E00C6E5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE passwort_reset_anfragen');
    }
}
