<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625205011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tisch-Chat: Tabelle chat_nachrichten (tischgebunden, Absender SET NULL bei Loeschung)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_nachrichten (id UUID NOT NULL, absender_name VARCHAR(50) NOT NULL, text VARCHAR(500) NOT NULL, erstellt_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tisch_id UUID NOT NULL, absender_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9BE6CCBB67A0F809 ON chat_nachrichten (tisch_id)');
        $this->addSql('CREATE INDEX IDX_9BE6CCBB3FE28B6A ON chat_nachrichten (absender_id)');
        $this->addSql('CREATE INDEX idx_chat_tisch_zeit ON chat_nachrichten (tisch_id, erstellt_am)');
        $this->addSql('ALTER TABLE chat_nachrichten ADD CONSTRAINT FK_9BE6CCBB67A0F809 FOREIGN KEY (tisch_id) REFERENCES tische (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE chat_nachrichten ADD CONSTRAINT FK_9BE6CCBB3FE28B6A FOREIGN KEY (absender_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_nachrichten DROP CONSTRAINT FK_9BE6CCBB67A0F809');
        $this->addSql('ALTER TABLE chat_nachrichten DROP CONSTRAINT FK_9BE6CCBB3FE28B6A');
        $this->addSql('DROP TABLE chat_nachrichten');
    }
}
