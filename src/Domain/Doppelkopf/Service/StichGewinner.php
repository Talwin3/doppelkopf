<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenwert;

/**
 * Bestimmt den Gewinner eines Stichs nach DDV-Regeln.
 *
 * Eingang: Array von [sitzplatz => Karte] in Spielreihenfolge (erste angespielt = erster Eintrag).
 * Ausgang: Sitzplatz des Gewinners.
 */
final class StichGewinner
{
    /**
     * @param array<int, Karte> $karten          Sitzplatz → Karte, in Spielreihenfolge
     * @param bool              $zweiteDulleSticht Falls true: die zweite Herz-Zehn schlägt die erste
     */
    public function bestimme(array $karten, TrumpfOrdnung $ordnung, bool $zweiteDulleSticht = false): int
    {
        if (count($karten) !== 4) {
            throw new \InvalidArgumentException('Ein Stich besteht aus genau 4 Karten.');
        }

        $sitzplaetze = array_keys($karten);
        $kartenWerte = array_values($karten);

        $angespielteFarbe = $ordnung->fehlfarbe($kartenWerte[0]);
        $angespieltTrumpf = $ordnung->istTrumpf($kartenWerte[0]);

        $gewinnerIndex = 0;

        for ($i = 1; $i < 4; $i++) {
            $gewinnerKarte    = $kartenWerte[$gewinnerIndex];
            $aktuelleKarte    = $kartenWerte[$i];
            $gewinnerIstTrumpf = $ordnung->istTrumpf($gewinnerKarte);
            $aktuellIstTrumpf  = $ordnung->istTrumpf($aktuelleKarte);

            if ($aktuellIstTrumpf && !$gewinnerIstTrumpf) {
                $gewinnerIndex = $i;
                continue;
            }

            if ($aktuellIstTrumpf && $gewinnerIstTrumpf) {
                $neuerRang = $ordnung->trumpfRang($aktuelleKarte);
                $alterRang = $ordnung->trumpfRang($gewinnerKarte);

                // "Zweite Dulle sticht erste": beide Karten sind Herz-Zehn → aktuelle (spätere) gewinnt
                if ($zweiteDulleSticht
                    && $aktuelleKarte->wert === Kartenwert::ZEHN
                    && $gewinnerKarte->wert === Kartenwert::ZEHN
                    && $neuerRang === $alterRang
                ) {
                    $gewinnerIndex = $i;
                    continue;
                }

                if ($neuerRang > $alterRang) {
                    $gewinnerIndex = $i;
                }
                continue;
            }

            if (!$aktuellIstTrumpf && !$gewinnerIstTrumpf) {
                $aktuelleFarbe = $ordnung->fehlfarbe($aktuelleKarte);
                if ($aktuelleFarbe === $angespielteFarbe
                    && $ordnung->fehlfarbenRang($aktuelleKarte) > $ordnung->fehlfarbenRang($gewinnerKarte)
                    && !$angespieltTrumpf
                ) {
                    $gewinnerIndex = $i;
                }
            }
        }

        return $sitzplaetze[$gewinnerIndex];
    }
}
