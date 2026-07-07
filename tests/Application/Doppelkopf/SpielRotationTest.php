<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\SpielAbschlussService;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Verifiziert das reihum wechselnde „Rauskommen": Nach jedem Spiel rückt die
 * Vorhand (Sitzplatz 1 = wer zuerst herauskommt) einen Platz weiter. Bei besetzter
 * Warteschlange rotiert zusätzlich reihum ein Spieler raus/rein.
 *
 * Getrieben über den öffentlichen Pfad {@see SpielAbschlussService::abschliessen()},
 * der die Rotation als Teil der Nach-Spiel-Abrechnung vorbereitet.
 */
final class SpielRotationTest extends DoppelkopfIntegrationTestCase
{
    private SpielAbschlussService $abschluss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->abschluss = static::getContainer()->get(SpielAbschlussService::class);
    }

    public function testVorhandRotiertReihumOhneWarteschlange(): void
    {
        $tischId = $this->vollerTisch(['alice', 'bob', 'carol', 'dave'])->getId();

        // Vorher: alice=1, bob=2, carol=3, dave=4.
        self::assertSame(
            ['1' => 'alice', '2' => 'bob', '3' => 'carol', '4' => 'dave'],
            $this->sitzBelegung($tischId),
        );

        $this->spielAbschliessen($tischId);

        // Vorhand rückt weiter: alter Platz 2 wird neuer Platz 1, der alte Vorhand
        // rutscht auf Platz 4. Niemand verlässt den Tisch (keine Warteschlange).
        self::assertSame(
            ['1' => 'bob', '2' => 'carol', '3' => 'dave', '4' => 'alice'],
            $this->sitzBelegung($tischId),
        );
        self::assertSame([], $this->warteschlange($tischId));
    }

    public function testVorhandUndWarteschlangeRotierenReihum(): void
    {
        $tisch = $this->vollerTisch(['alice', 'bob', 'carol', 'dave']);
        // eve als 5. Spieler → Warteschlange.
        $this->beitritt->beitreten($tisch, $this->neuerUser('eve'));
        $tischId = $tisch->getId();

        self::assertSame(['eve'], $this->warteschlange($tischId), 'Vorbedingung: eve wartet');

        $this->spielAbschliessen($tischId);

        // Vorhand rückt weiter UND der bisherige Vorhand (alice) setzt aus, eve rückt nach.
        self::assertSame(
            ['1' => 'bob', '2' => 'carol', '3' => 'dave', '4' => 'eve'],
            $this->sitzBelegung($tischId),
        );
        self::assertSame(['alice'], $this->warteschlange($tischId));
    }

    /** @param list<string> $namen genau 4 Namen; erster wird Ersteller (Sitzplatz 1). */
    private function vollerTisch(array $namen): Tisch
    {
        $ersteller = $this->neuerUser($namen[0]);
        $tisch = $this->beitritt->erstelleTisch($ersteller, 'Rotations-Tisch', ZugangsModusTyp::OFFEN);
        foreach (array_slice($namen, 1) as $name) {
            $this->beitritt->beitreten($tisch, $this->neuerUser($name));
        }

        return $tisch;
    }

    private function spielAbschliessen(\Symfony\Component\Uid\Uuid $tischId): void
    {
        // Frisch laden: vorherige em->clear()-Aufrufe (sitzBelegung) hätten einen
        // übergebenen Tisch detached → Persist mit managed Instanz.
        $this->em->clear();
        $tisch = $this->tischRepo->find($tischId);
        self::assertInstanceOf(Tisch::class, $tisch);

        $spiel = new Spiel();
        $spiel->setTisch($tisch);
        $spiel->setStatus(SpielStatus::LAUFEND);
        $spiel->setVariante(SpielVariante::NORMALSPIEL);
        $this->em->persist($spiel);
        $this->em->flush();

        $this->abschluss->abschliessen($spiel);
    }

    /**
     * Sitzplatz-Nummer (als String-Key) → Username der vier aktiven Spieler.
     *
     * @return array<string, string>
     */
    private function sitzBelegung(\Symfony\Component\Uid\Uuid $tischId): array
    {
        $this->em->clear();
        $tisch = $this->tischRepo->find($tischId);
        self::assertInstanceOf(Tisch::class, $tisch);

        $belegung = [];
        foreach ($tisch->getAktiveSpieler() as $ts) {
            $belegung[(string) $ts->getSitzplatz()] = $ts->getAnzeigeName();
        }
        ksort($belegung);

        return $belegung;
    }

    /**
     * Usernamen der Warteschlange in Reihenfolge.
     *
     * @return list<string>
     */
    private function warteschlange(\Symfony\Component\Uid\Uuid $tischId): array
    {
        $this->em->clear();
        $tisch = $this->tischRepo->find($tischId);
        self::assertInstanceOf(Tisch::class, $tisch);

        $warteschlange = $tisch->getWarteschlange()->toArray();
        usort($warteschlange, fn($a, $b) => $a->getPositionInWarteschlange() <=> $b->getPositionInWarteschlange());

        return array_map(fn($ts) => $ts->getAnzeigeName(), $warteschlange);
    }
}
