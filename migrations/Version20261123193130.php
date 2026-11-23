<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123193130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gesamtkonto Stufe 3: users.in_bestenlisten (Profil-Schalter "nicht in Bestenlisten")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD in_bestenlisten BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP in_bestenlisten');
    }
}
