<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\BewertungsStatistik;
use PHPUnit\Framework\TestCase;

/**
 * Rechenwerk der Bewertungs-Diagnose: Gewichts-Sweep und Trennschärfe.
 *
 * Die Zahlen werden hier von Hand gesetzt, damit die Auswertung unabhängig von der
 * Determinisierung prüfbar ist.
 */
final class BewertungsStatistikTest extends TestCase
{
    public function testGewichtsSweepZaehltNurEchteWahlaenderungen(): void
    {
        $s = new BewertungsStatistik();

        // Kandidat 0 holt mehr Augen (140 gegen 120), Kandidat 1 gewinnt dafür das Spiel
        // (s = +1 gegen −1 je Welt). Augen (w=0) wählen 0; der Punkteanteil dreht die Wahl,
        // sobald 4w > 20 gilt, also ab w = 6 – alle Sweep-Gewichte liegen darüber.
        $punkte = [
            [-1.0, -1.0],
            [+1.0, +1.0],
        ];
        $augen = [
            [70.0, 70.0],
            [60.0, 60.0],
        ];
        $s->erfasse($punkte, $augen);

        self::assertSame(1, $s->zuege);
        self::assertSame(1, $s->wahlGeaendert[50], 'w=50 muss die Karte drehen.');
        self::assertSame(1, $s->wahlGeaendert[100]);
        self::assertSame(1, $s->wahlGeaendert[200]);
        self::assertSame(1, $s->wahlGeaendert[500]);
        self::assertSame(2.0, $s->kandidatenSchnitt());
        self::assertSame(2.0, $s->weltenSchnitt());
    }

    public function testGleicheWahlZaehltNichtAlsAenderung(): void
    {
        $s = new BewertungsStatistik();
        // Kandidat 1 ist in beiden Maßstäben besser – kein Gewicht kann etwas drehen.
        $s->erfasse([[-1.0, -1.0], [+1.0, +1.0]], [[60.0, 60.0], [70.0, 70.0]]);

        foreach ([50, 100, 200, 500] as $w) {
            self::assertSame(0, $s->wahlGeaendert[$w], sprintf('w=%d darf nichts drehen.', $w));
        }
    }

    public function testEinstimmigeWeltenGeltenAlsAbgesichert(): void
    {
        $s = new BewertungsStatistik();
        // Jede Welt sagt dasselbe: Abstand ohne Streuung → t = INF, nichts unentschieden.
        $s->erfasse([[+1.0, +1.0, +1.0], [-1.0, -1.0, -1.0]], [[70.0, 70.0, 70.0], [60.0, 60.0, 60.0]]);

        self::assertSame(0, $s->unentschiedenAugen);
        self::assertSame(0, $s->unentschiedenPunkte);
        self::assertSame(0.0, $s->quoteUnentschiedenPunkte());
        self::assertSame(1, $s->einstimmigAugen, 'Streuung null zählt als einstimmig, nicht in den t-Schnitt.');
        self::assertSame(1, $s->einstimmigPunkte);
        self::assertSame(0.0, $s->tSchnittAugen(), 'Ohne endliche t-Werte bleibt der Schnitt 0 statt ∞.');
    }

    public function testHochrechnungAufFuenffacheWeltenzahl(): void
    {
        $s = new BewertungsStatistik();
        // Augen-Vorsprung von +10 je Welt bei einer Streuung von 11,5 → t ≈ 1,73: bei r=60
        // unentschieden, hochgerechnet auf die fünffache Weltenzahl (t · √5 ≈ 3,9) abgesichert.
        $s->erfasse(
            [[+1.0, +1.0, +1.0, +1.0], [-1.0, -1.0, -1.0, -1.0]],
            [[80.0, 60.0, 80.0, 60.0], [60.0, 60.0, 60.0, 60.0]],
        );

        self::assertSame(1, $s->unentschiedenAugen, 'Bei r=60 trägt der Abstand nicht.');
        self::assertSame(0, $s->unentschiedenBei300Augen, 'Fünffach so viele Welten würden ihn absichern.');
        self::assertSame(0, $s->unentschiedenBei300Punkte);
    }

    public function testStarkStreuenderVorsprungGiltAlsNichtAbgesichert(): void
    {
        $s = new BewertungsStatistik();
        // Kandidat 0 liegt im Mittel knapp vorn (+1 Augen), streut aber um ±30 –
        // bei vier Welten trägt das nicht. Die Punkte sind hier einig, also abgesichert.
        $s->erfasse(
            [[+1.0, +1.0, +1.0, +1.0], [-1.0, -1.0, -1.0, -1.0]],
            [[90.0, 30.0, 90.0, 32.0], [60.0, 60.0, 60.0, 60.0]],
        );

        self::assertSame(1, $s->unentschiedenAugen, 'Der Augen-Vorsprung ist reines Rauschen.');
        self::assertSame(0, $s->unentschiedenPunkte, 'Die Punkte sind über alle Welten einig.');
        self::assertLessThan(1.96, $s->tSchnittAugen());
    }

    public function testZuegeOhneAlternativeOderOhneStreuungWerdenIgnoriert(): void
    {
        $s = new BewertungsStatistik();
        $s->erfasse([[+1.0, +1.0]], [[70.0, 70.0]]);          // nur ein Kandidat
        $s->erfasse([[+1.0], [-1.0]], [[70.0], [60.0]]);      // nur eine Welt

        self::assertSame(0, $s->zuege);
        self::assertSame(0.0, $s->tSchnittAugen());
    }

    /**
     * Die Gegenprobe zur Trennschärfe: ein Münzwurf zwischen zwei fast gleichwertigen Karten
     * ist harmlos und darf nicht als Verlust gezählt werden.
     */
    public function testKleinerAbstandGiltAlsGleichwertigUndNichtAlsTeurerMuenzwurf(): void
    {
        $s = new BewertungsStatistik();
        // Vorsprung von 2 Augen im Mittel, aber je Welt schwankt die Differenz um ±20
        // (t ≈ 0,17). Entscheidend ist die Streuung der *Differenz*: ein in jeder Welt
        // gleicher Vorsprung wäre trotz kleinen Betrags perfekt abgesichert.
        $s->erfasse(
            [[+1.0, +1.0, +1.0, +1.0], [+1.0, +1.0, +1.0, +1.0]],
            [[82.0, 42.0, 82.0, 42.0], [60.0, 60.0, 60.0, 60.0]],
        );

        self::assertSame(1, $s->unentschiedenAugen, 'Statistisch ist der Vorsprung nicht abgesichert …');
        self::assertSame(1, $s->praktischGleichwertig, '… sachlich aber belanglos.');
        self::assertSame(0, $s->teuerUndUnentschieden);
        self::assertSame(2.0, $s->abstandMedianAugen());
    }

    public function testSpuerbarerAbstandImRauschenZaehltAlsTeurerMuenzwurf(): void
    {
        $s = new BewertungsStatistik();
        // Vorsprung von 15 Augen im Mittel, Differenz schwankt um ±40 → t ≈ 0,65:
        // hier steht wirklich etwas auf dem Spiel und geht trotzdem im Rauschen unter.
        $s->erfasse(
            [[+1.0, +1.0, +1.0, +1.0], [+1.0, +1.0, +1.0, +1.0]],
            [[115.0, 35.0, 115.0, 35.0], [60.0, 60.0, 60.0, 60.0]],
        );

        self::assertSame(1, $s->unentschiedenAugen);
        self::assertSame(0, $s->praktischGleichwertig);
        self::assertSame(1, $s->teuerUndUnentschieden);
        self::assertSame(100.0, $s->quoteTeuerUndUnentschieden());
    }

    public function testMedianIstRobustGegenAusreisser(): void
    {
        $s = new BewertungsStatistik();
        // Drei Züge: zwei mit Abstand 10, einer mit Abstand 100. Der Median bleibt bei 10.
        foreach ([10.0, 10.0, 100.0] as $abstand) {
            $s->erfasse(
                [[+1.0, +1.0, +1.0, +1.0], [+1.0, +1.0, +1.0, +1.0]],
                [
                    [60.0 + $abstand, 40.0 + $abstand, 60.0 + $abstand, 40.0 + $abstand],
                    [60.0, 40.0, 60.0, 40.0],
                ],
            );
        }

        self::assertSame(3, $s->zuege);
        self::assertSame(10.0, $s->abstandMedianAugen());
    }

    public function testZuruecksetzenLeertAlleZaehler(): void
    {
        $s = new BewertungsStatistik();
        $s->erfasse([[-1.0, -1.0], [+1.0, +1.0]], [[70.0, 70.0], [60.0, 60.0]]);
        $s->zuruecksetzen();

        self::assertSame(0, $s->zuege);
        self::assertSame(0, $s->wahlGeaendert[500]);
        self::assertSame(0.0, $s->tSummePunkte);
    }
}
