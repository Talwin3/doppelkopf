<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;

/**
 * Bestimmt den Gewinner eines Stichs nach DDV-Regeln.
 *
 * Eingang: Array von [sitzplatz => Karte] in Spielreihenfolge (erste angespielt = erster Eintrag).
 * Ausgang: Sitzplatz des Gewinners.
 */
final class StichGewinner
{
    public function __construct(private readonly TrumpfOrdnung $trumpfOrdnung) {}

    /**
     * @param array<int, Karte> $karten Sitzplatz → Karte, in Spielreihenfolge
     */
    public function bestimme(array $karten): int
    {
        if (count($karten) !== 4) {
            throw new \InvalidArgumentException('Ein Stich besteht aus genau 4 Karten.');
        }

        $sitzplaetze = array_keys($karten);
        $kartenWerte = array_values($karten);

        $angespielteFarbe = $this->trumpfOrdnung->fehlfarbe($kartenWerte[0]);
        $angespieltTrumpf = $this->trumpfOrdnung->istTrumpf($kartenWerte[0]);

        $gewinnerIndex = 0;

        for ($i = 1; $i < 4; $i++) {
            $gewinnerKarte  = $kartenWerte[$gewinnerIndex];
            $aktuelleKarte  = $kartenWerte[$i];
            $gewinnerIstTrumpf = $this->trumpfOrdnung->istTrumpf($gewinnerKarte);
            $aktuellIstTrumpf  = $this->trumpfOrdnung->istTrumpf($aktuelleKarte);

            if ($aktuellIstTrumpf && !$gewinnerIstTrumpf) {
                // Trumpf schlägt Fehlfarbe immer
                $gewinnerIndex = $i;
                continue;
            }

            if ($aktuellIstTrumpf && $gewinnerIstTrumpf) {
                // Höherer Trumpfrang gewinnt; bei Gleichheit (zwei Dullen) gewinnt die erste
                if ($this->trumpfOrdnung->trumpfRang($aktuelleKarte) > $this->trumpfOrdnung->trumpfRang($gewinnerKarte)) {
                    $gewinnerIndex = $i;
                }
                continue;
            }

            if (!$aktuellIstTrumpf && !$gewinnerIstTrumpf) {
                // Beide Fehlfarbe: aktuelle Karte gewinnt nur wenn gleiche Farbe wie Anspiel und höherer Rang
                $aktuelleFarbe = $this->trumpfOrdnung->fehlfarbe($aktuelleKarte);
                if ($aktuelleFarbe === $angespielteFarbe
                    && $this->trumpfOrdnung->fehlfarbenRang($aktuelleKarte) > $this->trumpfOrdnung->fehlfarbenRang($gewinnerKarte)
                    && !$angespieltTrumpf) {
                    $gewinnerIndex = $i;
                }
                // Andere Fehlfarbe (Abwurf): gewinnt nie
            }
            // Fehlfarbe gegen Trumpf-Gewinner: kann nicht gewinnen → nichts tun
        }

        return $sitzplaetze[$gewinnerIndex];
    }
}
