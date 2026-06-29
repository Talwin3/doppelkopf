<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\SpielVariante;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

final class SchweinchenHalterTest extends DoppelkopfIntegrationTestCase
{
    private function factory(): TrumpfOrdnungFactory
    {
        return static::getContainer()->get(TrumpfOrdnungFactory::class);
    }

    public function testErkenntSchweinchenHalter(): void
    {
        $host  = $this->neuerUser('schwein_host');
        $tisch = $this->beitritt->erstelleTisch($host, 'Schwein-Tisch', ZugangsModusTyp::OFFEN);

        $regelwerk = $tisch->getRegelEinstellungen();
        $regelwerk['schweinchen'] = true;
        $tisch->setRegelEinstellungen($regelwerk);

        $spiel = $this->laufendesSpiel($tisch);
        $spiel->setVariante(SpielVariante::NORMALSPIEL);

        // Spieler mit beiden Karo-Assen = Schweinchen.
        $halter = new SpielTeilnehmer();
        $halter->setSpiel($spiel);
        $halter->setUser($host);
        $halter->setSitzplatz(1);
        $halter->setStartkartenIds(['KARO_ASS_1', 'KARO_ASS_2', 'KREUZ_DAME_1']);
        $this->em->persist($halter);
        $this->em->flush();

        $halterId = $halter->getId();
        $spiel = $this->frischesSpiel($spiel->getId());
        $result = $this->factory()->schweinchenHalter($spiel);

        self::assertNotNull($result['teilnehmer']);
        self::assertSame((string) $halterId, (string) $result['teilnehmer']->getId());
        self::assertFalse($result['super'], 'Ohne Superschweinchen-Regel kein Super');
    }

    public function testKeinSchweinchenWennRegelAus(): void
    {
        $host  = $this->neuerUser('schwein_aus');
        $tisch = $this->beitritt->erstelleTisch($host, 'Schwein-Aus-Tisch', ZugangsModusTyp::OFFEN);
        // Regel schweinchen bleibt Default false.

        $spiel = $this->laufendesSpiel($tisch);
        $spiel->setVariante(SpielVariante::NORMALSPIEL);

        $halter = new SpielTeilnehmer();
        $halter->setSpiel($spiel);
        $halter->setUser($host);
        $halter->setSitzplatz(1);
        $halter->setStartkartenIds(['KARO_ASS_1', 'KARO_ASS_2']);
        $this->em->persist($halter);
        $this->em->flush();

        $spiel = $this->frischesSpiel($spiel->getId());
        self::assertNull($this->factory()->schweinchenHalter($spiel)['teilnehmer']);
    }

    /** Lädt das Spiel frisch, damit die Teilnehmer-Collection aus der DB befüllt ist. */
    private function frischesSpiel(\Symfony\Component\Uid\Uuid $spielId): Spiel
    {
        $this->em->clear();
        $spiel = $this->em->getRepository(Spiel::class)->find($spielId);
        self::assertInstanceOf(Spiel::class, $spiel);

        return $spiel;
    }
}
