<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616154507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1: Tisch, TischSpieler, SpielerZugangsListe (Lobby-Fundament)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE spieler_zugangsliste (id UUID NOT NULL, typ VARCHAR(10) NOT NULL, erstellt_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, inhaber_id UUID NOT NULL, ziel_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_40EF085338CEC3FC ON spieler_zugangsliste (inhaber_id)');
        $this->addSql('CREATE INDEX IDX_40EF085343ADA22D ON spieler_zugangsliste (ziel_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_zugangsliste_eintrag ON spieler_zugangsliste (inhaber_id, ziel_id, typ)');
        $this->addSql('CREATE TABLE tisch_spieler (id UUID NOT NULL, sitzplatz INT DEFAULT NULL, position_in_warteschlange INT DEFAULT NULL, ist_bot BOOLEAN DEFAULT false NOT NULL, beigetreten_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tisch_id UUID NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5F5B279267A0F809 ON tisch_spieler (tisch_id)');
        $this->addSql('CREATE INDEX IDX_5F5B2792A76ED395 ON tisch_spieler (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_tisch_sitzplatz ON tisch_spieler (tisch_id, sitzplatz)');
        $this->addSql('CREATE UNIQUE INDEX uq_tisch_user ON tisch_spieler (tisch_id, user_id)');
        $this->addSql('CREATE TABLE tische (id UUID NOT NULL, name VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, zugangsmodus VARCHAR(10) NOT NULL, ist_gesperrt BOOLEAN DEFAULT false NOT NULL, erstellt_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, aktualisiert_am TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ersteller_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_16B4F53B7F945160 ON tische (ersteller_id)');
        $this->addSql('ALTER TABLE spieler_zugangsliste ADD CONSTRAINT FK_40EF085338CEC3FC FOREIGN KEY (inhaber_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE spieler_zugangsliste ADD CONSTRAINT FK_40EF085343ADA22D FOREIGN KEY (ziel_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tisch_spieler ADD CONSTRAINT FK_5F5B279267A0F809 FOREIGN KEY (tisch_id) REFERENCES tische (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tisch_spieler ADD CONSTRAINT FK_5F5B2792A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tische ADD CONSTRAINT FK_16B4F53B7F945160 FOREIGN KEY (ersteller_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE spieler_zugangsliste DROP CONSTRAINT FK_40EF085338CEC3FC');
        $this->addSql('ALTER TABLE spieler_zugangsliste DROP CONSTRAINT FK_40EF085343ADA22D');
        $this->addSql('ALTER TABLE tisch_spieler DROP CONSTRAINT FK_5F5B279267A0F809');
        $this->addSql('ALTER TABLE tisch_spieler DROP CONSTRAINT FK_5F5B2792A76ED395');
        $this->addSql('ALTER TABLE tische DROP CONSTRAINT FK_16B4F53B7F945160');
        $this->addSql('DROP TABLE spieler_zugangsliste');
        $this->addSql('DROP TABLE tisch_spieler');
        $this->addSql('DROP TABLE tische');
    }
}
