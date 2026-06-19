<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ON DELETE CASCADE auf alle FKs der Spiel-Kette (tische → spiele → gespielte_karten/spiel_teilnehmer/spiel_ansagen)';
    }

    public function up(Schema $schema): void
    {
        // spiele.tisch_id → tische
        $this->addSql('ALTER TABLE spiele DROP CONSTRAINT fk_39043e3d67a0f809');
        $this->addSql('ALTER TABLE spiele ADD CONSTRAINT fk_39043e3d67a0f809 FOREIGN KEY (tisch_id) REFERENCES tische (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // gespielte_karten.spiel_id → spiele
        $this->addSql('ALTER TABLE gespielte_karten DROP CONSTRAINT fk_2c15d8d23d4c6b07');
        $this->addSql('ALTER TABLE gespielte_karten ADD CONSTRAINT fk_2c15d8d23d4c6b07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // spiel_teilnehmer.spiel_id → spiele
        $this->addSql('ALTER TABLE spiel_teilnehmer DROP CONSTRAINT fk_fad4652f3d4c6b07');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD CONSTRAINT fk_fad4652f3d4c6b07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // spiel_ansagen.spiel_id → spiele
        $this->addSql('ALTER TABLE spiel_ansagen DROP CONSTRAINT fk_dbc90e753d4c6b07');
        $this->addSql('ALTER TABLE spiel_ansagen ADD CONSTRAINT fk_dbc90e753d4c6b07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spiele DROP CONSTRAINT fk_39043e3d67a0f809');
        $this->addSql('ALTER TABLE spiele ADD CONSTRAINT fk_39043e3d67a0f809 FOREIGN KEY (tisch_id) REFERENCES tische (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE gespielte_karten DROP CONSTRAINT fk_2c15d8d23d4c6b07');
        $this->addSql('ALTER TABLE gespielte_karten ADD CONSTRAINT fk_2c15d8d23d4c6b07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE spiel_teilnehmer DROP CONSTRAINT fk_fad4652f3d4c6b07');
        $this->addSql('ALTER TABLE spiel_teilnehmer ADD CONSTRAINT fk_fad4652f3d4c6b07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE spiel_ansagen DROP CONSTRAINT fk_dbc90e753d4c6b07');
        $this->addSql('ALTER TABLE spiel_ansagen ADD CONSTRAINT fk_dbc90e753d4c6b07 FOREIGN KEY (spiel_id) REFERENCES spiele (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
