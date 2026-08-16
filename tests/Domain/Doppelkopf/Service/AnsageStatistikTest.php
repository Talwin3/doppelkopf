<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\AnsageStatistik;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Rechenwerk der Erstansage-Diagnose: Zusammenfassung je Partei-Blatt und Schwellen-Sweep.
 *
 * Die Werte werden hier von Hand gesetzt, damit die Auswertung unabhängig vom Simulator
 * prüfbar ist.
 */
final class AnsageStatistikTest extends TestCase
{
    /**
     * Der Fehler, an dem die erste Fassung scheiterte: sie zählte jede Spieler-Abfrage
     * einzeln. Ein Blatt, in dem beide Spieler einer Partei gefragt werden, zählte dann
     * doppelt – und weil eine Partei nach ihrer Ansage nicht mehr gefragt wird, traf das
     * die schweigenden (verlierenden) Blätter härter als die ansagenden.
     */
    public function testFasstDieAbfragenEinesBlattesJeParteiZusammen(): void
    {
        $s = new AnsageStatistik();
        $s->aktivieren();

        // Ein Blatt: beide Re-Spieler werden gefragt, dazu ein Contra-Spieler.
        $s->verbucheErstansage(Team::RE, 70.0, true);
        $s->verbucheErstansage(Team::RE, 90.0, true);
        $s->verbucheErstansage(Team::KONTRA, 88.0, true);
        $s->blattAbschliessen([Team::RE->value => 130, Team::KONTRA->value => 110]);

        self::assertSame(2, $s->entscheidungspunkte(), 'Zwei Parteien, also zwei Einträge.');

        // Maßgeblich ist der Höchstwert: die Partei sagt an, sobald *einer* die Schwelle reißt.
        $bei85 = $this->zeile($s, 85.0);
        self::assertSame(1, $bei85['re'], 'Der Spieler mit 90 Augen zieht die Partei über 85.');
        self::assertSame(1, $bei85['contra']);

        $bei95 = $this->zeile($s, 95.0);
        self::assertSame(0, $bei95['re']);
        self::assertSame(0, $bei95['contra']);
    }

    /** Nur Abfragen am Ansagezeitpunkt zählen – die übrigen sind bloße Zwischenstände. */
    public function testUebergehtAbfragenMitUnpassenderFrist(): void
    {
        $s = new AnsageStatistik();
        $s->aktivieren();

        $s->verbucheErstansage(Team::RE, 200.0, false);
        $s->verbucheErstansage(Team::RE, 80.0, true);
        $s->blattAbschliessen([Team::RE->value => 130]);

        self::assertSame(1, $s->entscheidungspunkte());
        self::assertSame(0, $this->zeile($s, 100.0)['re'], 'Die 200 gehören nicht in die Erhebung.');
        self::assertSame(1, $this->zeile($s, 80.0)['re']);
    }

    /** Ohne eine einzige zulässige Abfrage entsteht kein Eintrag. */
    public function testBlattOhnePassendeFristBleibtLeer(): void
    {
        $s = new AnsageStatistik();
        $s->aktivieren();

        $s->verbucheErstansage(Team::KONTRA, 90.0, false);
        $s->blattAbschliessen([Team::KONTRA->value => 130]);

        self::assertTrue($s->istLeer());
    }

    /**
     * Gehalten hat eine Erstansage bei 121 Augen – für Kontra genügen 120, sobald auch Re
     * angesagt hat (TSR 7.1.1.3).
     */
    public function testAugengrenzeDerContraAnsageHaengtAnDerReAnsage(): void
    {
        $ohneRe = new AnsageStatistik();
        $ohneRe->aktivieren();
        $ohneRe->verbucheErstansage(Team::KONTRA, 90.0, true);
        $ohneRe->blattAbschliessen([Team::RE->value => 120, Team::KONTRA->value => 120], false);

        self::assertSame(0.0, $this->zeile($ohneRe, 90.0)['contraQuote'], '120 reichen ohne Re-Ansage nicht.');

        $mitRe = new AnsageStatistik();
        $mitRe->aktivieren();
        $mitRe->verbucheErstansage(Team::KONTRA, 90.0, true);
        $mitRe->blattAbschliessen([Team::RE->value => 120, Team::KONTRA->value => 120], true);

        self::assertSame(100.0, $this->zeile($mitRe, 90.0)['contraQuote'], 'Mit Re-Ansage genügen 120.');
    }

    /** Re braucht auch dann 121, wenn Re angesagt hat. */
    public function testReBrauchtImmer121(): void
    {
        $s = new AnsageStatistik();
        $s->aktivieren();
        $s->verbucheErstansage(Team::RE, 90.0, true);
        $s->blattAbschliessen([Team::RE->value => 120], true);

        self::assertSame(0.0, $this->zeile($s, 90.0)['reQuote']);
    }

    /** Abgeschaltet verbucht die Erhebung nichts – sie ist im Messlauf per Default aus. */
    public function testSammeltNurWennAktiviert(): void
    {
        $s = new AnsageStatistik();

        $s->verbucheErstansage(Team::RE, 90.0, true);
        $s->blattAbschliessen([Team::RE->value => 130]);

        self::assertTrue($s->istLeer());
    }

    /**
     * @return array{schwelle: float, re: int, reQuote: float, contra: int, contraQuote: float}
     */
    private function zeile(AnsageStatistik $s, float $schwelle): array
    {
        foreach ($s->sweep($schwelle, $schwelle, 5.0) as $zeile) {
            return $zeile;
        }

        self::fail(sprintf('Keine Sweep-Zeile für Schwelle %.0f.', $schwelle));
    }
}
