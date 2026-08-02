<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Klärungsfrist der Hochzeit: Bleibt sie nach den ersten drei Stichen ungeklärt,
 * spielt der Hochzeitsspieler allein weiter und wird als Solist abgerechnet.
 * Das hält die Spalte fest; die Variante bleibt HOCHZEIT.
 */
final class Version20260802180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ungeklaerte Hochzeit als Solo werten: spiele.hochzeit_als_solo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ADD hochzeit_als_solo BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP hochzeit_als_solo');
    }
}
