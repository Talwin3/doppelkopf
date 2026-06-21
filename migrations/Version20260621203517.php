<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621203517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'spiele.status auf VARCHAR(20): ARMUT_ANFRAGE/ARMUT_TAUSCH passten nicht in VARCHAR(10).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ALTER status TYPE VARCHAR(20)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele ALTER status TYPE VARCHAR(10)');
    }
}
