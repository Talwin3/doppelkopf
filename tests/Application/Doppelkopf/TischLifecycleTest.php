<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\TischVerlassenGesperrtException;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Integrationstests für die Tisch-Lifecycle-Logik aus Commit e4cac0b:
 *  - Verlassen-Sperre für sitzende Spieler bei laufendem Spiel
 *  - „menschenlos"-Markierung, wenn der letzte Mensch geht (sonst räumt der Worker nie auf)
 *  - dieselbe Markierung bei Konto-Löschung
 *
 * Die tatsächliche Löschung durch den Worker testet {@see \App\Tests\Command\BotWorkerCleanupTest}.
 */
final class TischLifecycleTest extends DoppelkopfIntegrationTestCase
{
    public function testSitzenderSpielerKannBeiLaufendemSpielNichtVerlassen(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Sperr-Tisch', ZugangsModusTyp::OFFEN);
        $this->laufendesSpiel($tisch);

        [$tisch, $alice] = $this->frischLaden($tisch->getId(), $alice->getId());

        $this->expectException(TischVerlassenGesperrtException::class);
        $this->beitritt->verlassen($tisch, $alice);
    }

    public function testWarteschlangenSpielerDarfWaehrendLaufendemSpielVerlassen(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Wartelisten-Tisch', ZugangsModusTyp::OFFEN);
        foreach (['bob', 'carol', 'dave'] as $name) {
            $this->beitritt->beitreten($tisch, $this->neuerUser($name));
        }
        $eve = $this->neuerUser('eve');
        $eveSpieler = $this->beitritt->beitreten($tisch, $eve); // 5. Spieler → Warteschlange
        self::assertFalse($eveSpieler->istAktiv(), 'Eve sollte in der Warteschlange sein (kein Sitzplatz)');

        $this->laufendesSpiel($tisch);

        [$tisch, $eve] = $this->frischLaden($tisch->getId(), $eve->getId());

        // Darf NICHT werfen — Warteschlangen-Spieler dürfen auch während eines Spiels gehen.
        $this->beitritt->verlassen($tisch, $eve);

        self::assertNull(
            $this->tischSpielerRepo->findByTischAndUser($tisch, $eve),
            'Eve sollte den Tisch verlassen haben',
        );
        self::assertNull(
            $tisch->getMenschenloseSeitAm(),
            'Tisch hat noch sitzende Menschen → nicht menschenlos',
        );
    }

    public function testLetzterMenschVerlaesstMarkiertTischAlsMenschenlos(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Verwaister Tisch', ZugangsModusTyp::OFFEN);
        self::assertNull($tisch->getMenschenloseSeitAm(), 'Vorbedingung: noch nicht menschenlos');

        $tischId = $tisch->getId();
        [$tisch, $alice] = $this->frischLaden($tischId, $alice->getId());

        $this->beitritt->verlassen($tisch, $alice);

        self::assertNotNull(
            $tisch->getMenschenloseSeitAm(),
            'Nach dem Weggang des letzten Menschen muss menschenloseSeitAm gesetzt sein',
        );
        self::assertContains(
            $tischId->toRfc4122(),
            $this->menschenloseTischIds(),
            'findMenschenlose() muss den verwaisten Tisch zurückgeben (sonst räumt der Worker ihn nie auf)',
        );
    }

    public function testTischMitVerbleibendemMenschBleibtNichtMenschenlos(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Belebter Tisch', ZugangsModusTyp::OFFEN);
        $bob = $this->neuerUser('bob');
        $this->beitritt->beitreten($tisch, $bob);

        $tischId = $tisch->getId();
        [$tisch, $bob] = $this->frischLaden($tischId, $bob->getId());

        // Kein laufendes Spiel → Verlassen erlaubt; Alice bleibt sitzen.
        $this->beitritt->verlassen($tisch, $bob);

        self::assertNull(
            $tisch->getMenschenloseSeitAm(),
            'Solange Alice sitzt, darf der Tisch nicht als menschenlos markiert werden',
        );
        self::assertNotContains($tischId->toRfc4122(), $this->menschenloseTischIds());
    }

    public function testKontoLoeschenMarkiertVerwaistenTischAlsMenschenlos(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Lösch-Tisch', ZugangsModusTyp::OFFEN);

        $tischId = $tisch->getId();
        [$tisch, $alice] = $this->frischLaden($tischId, $alice->getId());

        $this->profil->kontoLoeschen($alice);

        self::assertNotNull(
            $tisch->getMenschenloseSeitAm(),
            'Konto-Löschung des letzten Menschen muss den Tisch als menschenlos markieren',
        );
        self::assertNull(
            $this->tischSpielerRepo->findByTischAndUser($tisch, $alice),
            'Der Sitzplatz des gelöschten Kontos muss freigegeben sein',
        );
        self::assertContains($tischId->toRfc4122(), $this->menschenloseTischIds());
    }
}
