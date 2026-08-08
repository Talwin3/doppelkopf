<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\EndspielLoeser;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Exakte Endspielsuche.
 *
 * Ein Löser lässt sich nur an Stellungen prüfen, die klein genug sind, um sie von Hand
 * nachzurechnen – deshalb ein bis zwei Stiche mit weitgehend erzwungenen Zügen. Dazu die
 * Nullsummen-Invariante, die über viele zufällige Stellungen läuft und jede Verwechslung
 * von Parteien oder doppelt gezählte Stiche auffliegen ließe.
 *
 * ⚠️ Alle Stellungen müssen konsistent sein: Karten des laufenden Stichs liegen **nicht**
 * mehr in den Händen, und wer schon gelegt hat, hält eine Karte weniger.
 */
final class EndspielLoeserTest extends TestCase
{
    private EndspielLoeser $loeser;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->loeser  = new EndspielLoeser(new StichGewinner());
        $this->ordnung = new NormalspielTrumpfOrdnung();
    }

    private function k(string $id): Karte
    {
        return Karte::vonId($id);
    }

    /** Sitze 1+3 sind RE, 2+4 KONTRA. */
    private function teams(): array
    {
        return [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::RE, 4 => Team::KONTRA];
    }

    public function testLetzterStichGehtAnDenHoechstenTrumpf(): void
    {
        // Je eine Karte, Sitz 1 spielt an. Kreuz-Dame (Sitz 3, RE) ist der höchste Trumpf
        // und holt alles: 0 + 11 + 3 + 3 = 17 Augen für RE.
        $haende = [
            1 => [$this->k('KARO_NEUN_1')],
            2 => [$this->k('KARO_ASS_1')],
            3 => [$this->k('KREUZ_DAME_1')],
            4 => [$this->k('PIK_DAME_1')],
        ];

        self::assertSame(17, $this->loeser->augen($haende, [], 1, $this->teams(), Team::RE, $this->ordnung, false));
    }

    public function testNimmtDenTeurenStichStattDesBilligen(): void
    {
        // Sitz 1 (RE) ist letzter im laufenden Stich und hält zwei Trümpfe.
        // Laufender Stich (21 Augen): Karo-Zehn(2), Karo-Neun(3), Karo-Ass(4) – Sitz 4 führt.
        //
        //   Kreuz-Dame  -> holt Stich 1 (21+3 = 24) und die Karo-Neun holt danach als
        //                  einziger Trumpf den zweiten Stich (0+11+11+10 = 32)  => 56
        //   Karo-Neun   -> Stich 1 geht an KONTRA; Kreuz-Dame sticht Stich 2 (35)  => 35
        //
        // Der Löser muss die 56er-Linie finden.
        $haende = [
            1 => [$this->k('KREUZ_DAME_1'), $this->k('KARO_NEUN_1')],
            2 => [$this->k('KREUZ_ASS_1')],
            3 => [$this->k('KREUZ_ASS_2')],
            4 => [$this->k('KREUZ_ZEHN_1')],
        ];
        $stich = [
            2 => $this->k('KARO_ZEHN_1'),
            3 => $this->k('KARO_NEUN_2'),
            4 => $this->k('KARO_ASS_1'),
        ];

        self::assertSame(56, $this->loeser->augen($haende, $stich, 1, $this->teams(), Team::RE, $this->ordnung, false));
    }

    public function testRechnetDurchgaengigErzwungeneStellungExakt(): void
    {
        // Sitz 4 hat Pik-König angespielt, alle anderen bedienen zwangsweise Pik.
        //   Stich 1: Pik-König(4) Pik-Neun(1) Pik-Ass(2) Pik-Zehn(3) = 25 -> Sitz 2, KONTRA
        //   Stich 2: alles Trumpf, Herz-Zehn (Sitz 3, RE) ist die höchste
        //            Karo-Ass(2) 11 + Herz-Zehn(3) 10 + Karo-Dame(4) 3 + Kreuz-Bube(1) 2 = 26 -> RE
        $haende = [
            1 => [$this->k('KREUZ_BUBE_1'), $this->k('PIK_NEUN_1')],
            2 => [$this->k('KARO_ASS_1'), $this->k('PIK_ASS_1')],
            3 => [$this->k('HERZ_ZEHN_1'), $this->k('PIK_ZEHN_1')],
            4 => [$this->k('KARO_DAME_1')],
        ];
        $stich = [4 => $this->k('PIK_KOENIG_1')];

        self::assertSame(26, $this->loeser->augen($haende, $stich, 1, $this->teams(), Team::RE, $this->ordnung, false));
        self::assertSame(25, $this->loeser->augen($haende, $stich, 1, $this->teams(), Team::KONTRA, $this->ordnung, false));
    }

    /**
     * Was die eine Partei nicht holt, holt die andere. Die Invariante deckt vertauschte
     * Parteien, doppelt gezählte Stiche und verlorene Augen in einem Zug ab – und läuft
     * über viele zufällige Stellungen statt über einen Einzelfall.
     */
    public function testNullSummeUeberZufaelligeStellungen(): void
    {
        $vorrat = [
            'KREUZ_DAME_1', 'PIK_DAME_1', 'HERZ_DAME_1', 'KARO_DAME_1',
            'KREUZ_BUBE_1', 'PIK_BUBE_1', 'HERZ_BUBE_1', 'KARO_BUBE_1',
            'HERZ_ZEHN_1', 'KARO_ASS_1', 'KARO_ZEHN_1', 'KARO_KOENIG_1',
            'KREUZ_ASS_1', 'KREUZ_ZEHN_1', 'KREUZ_KOENIG_1', 'KREUZ_NEUN_1',
            'PIK_ASS_1', 'PIK_ZEHN_1', 'PIK_KOENIG_1', 'PIK_NEUN_1',
            'HERZ_ASS_1', 'HERZ_KOENIG_1', 'HERZ_NEUN_1', 'KARO_NEUN_1',
        ];

        mt_srand(20260808);

        for ($fall = 0; $fall < 25; $fall++) {
            $proHand = 3;
            $ids     = $vorrat;
            shuffle($ids);

            $haende = [];
            $gesamt = 0;
            for ($sitz = 1; $sitz <= 4; $sitz++) {
                $haende[$sitz] = [];
                for ($i = 0; $i < $proHand; $i++) {
                    $karte           = $this->k((string) array_pop($ids));
                    $haende[$sitz][] = $karte;
                    $gesamt         += $karte->augen();
                }
            }

            $re     = $this->loeser->augen($haende, [], 1, $this->teams(), Team::RE, $this->ordnung, false);
            $kontra = $this->loeser->augen($haende, [], 1, $this->teams(), Team::KONTRA, $this->ordnung, false);

            self::assertSame(
                $gesamt,
                $re + $kontra,
                sprintf('Fall %d: RE %d + KONTRA %d sollte %d Augen ergeben.', $fall, $re, $kontra, $gesamt),
            );
        }
    }

    /**
     * Zwei gleiche Karten auf einer Hand sind derselbe Zug. Die Entdopplung darf den Wert
     * nicht verändern – sie soll nur den Suchbaum kleiner machen.
     */
    public function testDoppelteKarteAendertDenWertNicht(): void
    {
        $mitPaar = [
            1 => [$this->k('PIK_NEUN_1'), $this->k('PIK_NEUN_2')],
            2 => [$this->k('KARO_ASS_1'), $this->k('PIK_ASS_1')],
            3 => [$this->k('HERZ_ZEHN_1'), $this->k('PIK_ZEHN_1')],
            4 => [$this->k('KARO_DAME_1'), $this->k('PIK_KOENIG_1')],
        ];

        $re     = $this->loeser->augen($mitPaar, [], 1, $this->teams(), Team::RE, $this->ordnung, false);
        $kontra = $this->loeser->augen($mitPaar, [], 1, $this->teams(), Team::KONTRA, $this->ordnung, false);

        self::assertSame(11 + 11 + 10 + 10 + 3 + 4, $re + $kontra);
    }
}
