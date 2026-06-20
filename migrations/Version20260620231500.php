<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260620231500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'spiele.abschluss_faellig_am — verzögerter Spielabschluss (Endstich kurz sichtbar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ADD abschluss_faellig_am TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP abschluss_faellig_am');
    }
}
