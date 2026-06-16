<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spiel: aktueller_zug_begann_am für Bot-Timeout-Erkennung';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE spiele ADD aktueller_zug_begann_am TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL'
        );
        $this->addSql(
            'COMMENT ON COLUMN spiele.aktueller_zug_begann_am IS \'(DC2Type:datetime_immutable)\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP aktueller_zug_begann_am');
    }
}
