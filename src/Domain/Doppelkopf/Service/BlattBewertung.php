<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Schätzt die Stärke eines Blattes in **Augen** – nach dem Erwartungswert-Schema des
 * Essener Systems (Kapitel 3.2, siehe Referenz `ref-essener-system`).
 *
 * Die Blattstärke ist die Summe der Einzel-Erwartungswerte aller potentiellen Stiche,
 * jeder als *Wert × Wahrscheinlichkeit*. Das ist erheblich informierter als bloßes
 * Trumpfzählen: Ein Ass ist je nach Besetzung der Farbe unterschiedlich viel wert, und
 * eine Chicane in Kreuz wiegt schwerer als ein vierter Bube.
 *
 * Referenzwerte des Systems: der durchschnittliche RE-Spieler erzielt ~68 Augen, der
 * durchschnittliche KONTRA-Spieler ~52 (im eigenen Simulator mit 68,5 / 51,6 bestätigt).
 * Für eine Erstansage nennt das System **Re ab 75, Kontra ab 85 Augen**.
 *
 * Reine Logik ohne Datenbankzugriff.
 *
 * ⚠️ **Bewusste Vereinfachungen**, weil die Vorlage sie nicht eindeutig hergibt (die
 * Tabelle ist in der Quelle beschädigt und mindestens eine Beispielrechnung darin
 * fehlerhaft):
 *  - Wo das System Spannen angibt (z. B. „20–30" für die Dulle), wird die Mitte genommen.
 *  - Als „Stechkarte" beim Abstechen einer Fehlfarbe gilt der niedrigste eigene Trumpf.
 *  - Trumpflänge und -kürze bleiben unbewertet; das System nennt diese Lücke selbst.
 * Die Konstanten sind daher Startwerte zum Messen, keine gesetzten Wahrheiten.
 */
final class BlattBewertung
{
    /** Gesamt-Augenzahl einer schwarzen Fehlfarbe (2×11 + 2×10 + 2×4 + 2×0). */
    private const AUGEN_SCHWARZ = 50;

    /** Gesamt-Augenzahl der Fehlfarbe Herz – ohne die Zehnen, die als Dullen Trumpf sind. */
    private const AUGEN_HERZ = 30;

    private const WERT_DULLE                  = 25.0;  // System: 20–30, Wahrscheinlichkeit 100 %
    private const WERT_KREUZ_DAME             = 17.5;  // System: 15–20
    private const WERT_KREUZ_DAME_MIT_DULLE   = 12.5;  // System: 10–15
    private const WERT_BEIDE_DAMEN            = 22.5;  // System: 20–25
    private const WERT_BEIDE_DAMEN_MIT_DULLE  = 17.5;  // System: 15–20
    private const P_DAMEN                     = 0.75;
    private const P_DAMEN_MIT_DULLE           = 1.0;

    private const STECH_BONUS_SCHWARZ   = 20.0;
    private const STECH_BONUS_HERZ      = 15.0;
    private const P_CHICANE_SCHWARZ     = 0.90;
    private const P_CHICANE_HERZ        = 0.75;
    private const P_SINGLETON           = 0.50;
    private const P_SINGLETON_NACH_CHICANE = 0.66;

    /** Laufwahrscheinlichkeit eines schwarzen Anspiel-Asses nach Anzahl eigener Karten der Farbe. */
    private const P_ASS_SCHWARZ         = [1 => 0.90, 2 => 0.75, 3 => 0.66, 4 => 0.50, 5 => 0.25];
    private const P_ASS_SCHWARZ_ANSAGE  = [1 => 0.90, 2 => 0.90, 3 => 0.75, 4 => 0.66, 5 => 0.50];
    private const P_ASS_HERZ            = [1 => 0.50, 2 => 0.25];
    private const P_ASS_HERZ_ANSAGE     = [1 => 0.66, 2 => 0.50];

    /**
     * Erwartete Augenausbeute dieses Blattes.
     *
     * @param Karte[] $hand
     * @param bool    $mitAnsage Nach eigener Ansage laufen Asse häufiger – das System
     *                           führt dafür eigene Wahrscheinlichkeiten.
     */
    public function augen(array $hand, TrumpfOrdnung $ordnung, bool $mitAnsage = false): float
    {
        $truempfe   = [];
        $nachFarbe  = [];
        foreach ($hand as $karte) {
            if ($ordnung->istTrumpf($karte)) {
                $truempfe[] = $karte;
                continue;
            }
            $farbe = $ordnung->fehlfarbe($karte);
            if ($farbe !== null) {
                $nachFarbe[$farbe->value][] = $karte;
            }
        }

        return $this->trumpfSpitzen($truempfe)
            + $this->fehlfarben($nachFarbe, $truempfe, $ordnung, $mitAnsage);
    }

    /** Dulle und Kreuz-/Pik-Damen – die Karten, die für sich genommen Stiche bedeuten. */
    private function trumpfSpitzen(array $truempfe): float
    {
        $dullen     = 0;
        $kreuzDamen = 0;
        $pikDamen   = 0;
        foreach ($truempfe as $k) {
            if ($k->farbe === Kartenfarbe::HERZ && $k->wert === Kartenwert::ZEHN) {
                $dullen++;
            } elseif ($k->wert === Kartenwert::DAME && $k->farbe === Kartenfarbe::KREUZ) {
                $kreuzDamen++;
            } elseif ($k->wert === Kartenwert::DAME && $k->farbe === Kartenfarbe::PIK) {
                $pikDamen++;
            }
        }

        $summe = $dullen * self::WERT_DULLE;
        if ($kreuzDamen === 0) {
            return $summe;
        }

        // Kreuz-Dame plus Pik-Dame ist mehr wert als die Kreuz-Dame allein; weitere Damen
        // erhöhen den Wert laut System nur noch geringfügig und bleiben unbewertet.
        $mitDulle = $dullen > 0;
        $wert = $pikDamen > 0
            ? ($mitDulle ? self::WERT_BEIDE_DAMEN_MIT_DULLE : self::WERT_BEIDE_DAMEN)
            : ($mitDulle ? self::WERT_KREUZ_DAME_MIT_DULLE : self::WERT_KREUZ_DAME);

        return $summe + $wert * ($mitDulle ? self::P_DAMEN_MIT_DULLE : self::P_DAMEN);
    }

    /**
     * Anspiel-Asse und das Abstechen kurzer Farben.
     *
     * @param array<string, Karte[]> $nachFarbe
     * @param Karte[]                $truempfe
     */
    private function fehlfarben(array $nachFarbe, array $truempfe, TrumpfOrdnung $ordnung, bool $mitAnsage): float
    {
        $stechkarte = $this->stechkarte($truempfe);
        $summe      = 0.0;

        foreach ([Kartenfarbe::KREUZ, Kartenfarbe::PIK, Kartenfarbe::HERZ] as $farbe) {
            $karten  = $nachFarbe[$farbe->value] ?? [];
            $anzahl  = count($karten);
            $istHerz = $farbe === Kartenfarbe::HERZ;

            // Abstechen: nur sinnvoll, solange überhaupt Trumpf da ist.
            if ($truempfe !== []) {
                if ($anzahl === 0) {
                    $summe += ($stechkarte + ($istHerz ? self::STECH_BONUS_HERZ : self::STECH_BONUS_SCHWARZ))
                        * ($istHerz ? self::P_CHICANE_HERZ : self::P_CHICANE_SCHWARZ);
                } elseif ($anzahl === 1) {
                    $summe += ($stechkarte + ($istHerz ? self::STECH_BONUS_HERZ : self::STECH_BONUS_SCHWARZ))
                        * self::P_SINGLETON;
                }
            }

            if ($anzahl === 0) {
                continue;
            }

            $eigeneAugen = array_sum(array_map(static fn(Karte $k) => $k->augen(), $karten));
            $asse        = count(array_filter($karten, static fn(Karte $k) => $k->wert === Kartenwert::ASS));
            if ($asse === 0) {
                continue;
            }

            $gesamt   = $istHerz ? self::AUGEN_HERZ : self::AUGEN_SCHWARZ;
            $fehlende = ($istHerz ? 6 : 8) - $anzahl;
            if ($fehlende < 1) {
                continue; // Farbe komplett auf der Hand – nichts mehr zu holen
            }

            $wert = 11.0 + 3.0 / $fehlende * max(0, $gesamt - $eigeneAugen);
            $summe += $wert * $this->laufwahrscheinlichkeit($anzahl, $istHerz, $mitAnsage);
        }

        return $summe;
    }

    /** Wie wahrscheinlich läuft das Anspiel-Ass durch, gegeben die Besetzung der Farbe? */
    private function laufwahrscheinlichkeit(int $eigeneKarten, bool $istHerz, bool $mitAnsage): float
    {
        $tabelle = $istHerz
            ? ($mitAnsage ? self::P_ASS_HERZ_ANSAGE : self::P_ASS_HERZ)
            : ($mitAnsage ? self::P_ASS_SCHWARZ_ANSAGE : self::P_ASS_SCHWARZ);

        if (isset($tabelle[$eigeneKarten])) {
            return $tabelle[$eigeneKarten];
        }

        // Längere Farben als die Tabelle kennt: das Ass läuft praktisch nicht mehr.
        return min($tabelle) / 2;
    }

    /**
     * Augenwert der Karte, mit der eine Fehlfarbe abgestochen würde.
     *
     * Nicht der niedrigste Trumpf: Zum Abstechen nimmt man einen, den man entbehren kann,
     * und holt dabei am liebsten Augen mit. Also der augenreichste Trumpf unterhalb der
     * Bilder – im Normalspiel typischerweise Karo-Ass oder Karo-Zehn. Das Original rechnet
     * genau so (Beispielblatt 2 setzt 10 an, und das ist dort die Karo-Zehn).
     *
     * @param Karte[] $truempfe
     */
    private function stechkarte(array $truempfe): float
    {
        $niedrige = array_filter($truempfe, static fn(Karte $k) => $k->wert !== Kartenwert::DAME
            && $k->wert !== Kartenwert::BUBE
            && !($k->farbe === Kartenfarbe::HERZ && $k->wert === Kartenwert::ZEHN));

        $kandidaten = $niedrige !== [] ? $niedrige : $truempfe;

        $beste = 0;
        foreach ($kandidaten as $k) {
            $beste = max($beste, $k->augen());
        }

        return (float) $beste;
    }
}
