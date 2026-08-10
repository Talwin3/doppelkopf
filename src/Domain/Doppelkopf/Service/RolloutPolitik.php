<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;

/**
 * Die schnelle Greedy-Kartenwahl, mit der eine determinisierte Welt zu Ende gespielt wird.
 *
 * Innerhalb einer Welt sind alle Hände bekannt – die Politik nutzt davon aber bewusst nur,
 * was ein Spieler an der Reihe auch wüsste: die eigene Hand, den laufenden Stich und die
 * Parteien. Sie schaut nie in fremde Hände.
 *
 * Herausgelöst aus {@see ProfiHeuristik}, weil sie an zwei Stellen gebraucht wird:
 *  - im Greedy-Rollout ({@see ProfiHeuristik::ausspielen}) als Politik aller vier Sitze,
 *  - im {@see EndspielLoeser} als **Gegnermodell** – dort spielen die nicht gesuchten Sitze
 *    genau diese Politik, statt optimal zu verteidigen (siehe {@see \App\Enum\EndspielPolitik}).
 *
 * Beide Stellen müssen dieselbe Politik spielen, sonst rechnet der Löser gegen einen Gegner,
 * den es im Rollout gar nicht gibt. Reine Logik ohne DB.
 */
final class RolloutPolitik
{
    /** Ab dieser Augenzahl im Stich lohnt das Stechen auch ohne letzte Position. */
    private const STECH_SCHWELLE = 10;

    /**
     * @param Karte[]           $hand   Handkarten des Sitzes am Zug (mind. eine)
     * @param array<int, Karte> $stich  Sitzplatz → Karte des laufenden Stichs, in Spielreihenfolge
     * @param array<int, Team>  $teams  Sitzplatz → Partei (in dieser Welt bekannt)
     */
    public function waehle(array $hand, array $stich, Team $eigenes, array $teams, ?Karte $angespielt, TrumpfOrdnung $ordnung): Karte
    {
        $legale = LegaleKarten::fuer($hand, $angespielt, $ordnung);

        if ($stich === []) {
            return $this->niedrigsteAugen($legale, $ordnung); // simpel anspielen
        }

        $gewinnerSitz  = array_key_first($stich);
        $gewinnerKarte = $stich[$gewinnerSitz];
        foreach ($stich as $sitz => $karte) {
            if ($this->schlaegt($karte, $gewinnerKarte, $angespielt, $ordnung)) {
                $gewinnerSitz  = $sitz;
                $gewinnerKarte = $karte;
            }
        }

        $partnerFuehrt = $teams[$gewinnerSitz] === $eigenes;
        $istLetzter    = count($stich) === 3;
        $stichAugen    = array_sum(array_map(fn(Karte $k) => $k->augen(), $stich));

        if ($partnerFuehrt) {
            return $istLetzter ? $this->hoechsteAugen($legale, $ordnung) : $this->niedrigsteAugen($legale, $ordnung);
        }

        $schlagende = array_values(array_filter(
            $legale,
            fn(Karte $k) => $this->schlaegt($k, $gewinnerKarte, $angespielt, $ordnung),
        ));
        if ($schlagende !== [] && ($istLetzter || $stichAugen >= self::STECH_SCHWELLE)) {
            return $this->billigsteSchlagende($schlagende, $ordnung);
        }

        return $this->niedrigsteAugen($legale, $ordnung);
    }

    public function schlaegt(Karte $kandidat, Karte $gewinner, ?Karte $angespielt, TrumpfOrdnung $ordnung): bool
    {
        $kandTrumpf = $ordnung->istTrumpf($kandidat);
        $gewTrumpf  = $ordnung->istTrumpf($gewinner);

        if ($kandTrumpf && !$gewTrumpf) {
            return true;
        }
        if (!$kandTrumpf && $gewTrumpf) {
            return false;
        }
        if ($kandTrumpf && $gewTrumpf) {
            return $ordnung->trumpfRang($kandidat) > $ordnung->trumpfRang($gewinner);
        }

        if ($angespielt === null || $ordnung->istTrumpf($angespielt)) {
            return false;
        }
        if ($ordnung->fehlfarbe($kandidat) !== $ordnung->fehlfarbe($angespielt)) {
            return false;
        }

        return $ordnung->fehlfarbenRang($kandidat) > $ordnung->fehlfarbenRang($gewinner);
    }

    /** @param Karte[] $schlagende */
    private function billigsteSchlagende(array $schlagende, TrumpfOrdnung $ordnung): Karte
    {
        usort($schlagende, function (Karte $a, Karte $b) use ($ordnung) {
            $aT = $ordnung->istTrumpf($a);
            $bT = $ordnung->istTrumpf($b);
            if ($aT !== $bT) {
                return $aT <=> $bT;
            }
            $aR = $aT ? $ordnung->trumpfRang($a) : $ordnung->fehlfarbenRang($a);
            $bR = $bT ? $ordnung->trumpfRang($b) : $ordnung->fehlfarbenRang($b);
            return $aR <=> $bR;
        });

        return $schlagende[0];
    }

    /** @param Karte[] $karten */
    private function hoechsteAugen(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, function (Karte $a, Karte $b) use ($ordnung) {
            if ($a->augen() !== $b->augen()) {
                return $b->augen() <=> $a->augen();
            }
            return $ordnung->istTrumpf($a) <=> $ordnung->istTrumpf($b);
        });

        return $karten[0];
    }

    /** @param Karte[] $karten */
    private function niedrigsteAugen(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, function (Karte $a, Karte $b) use ($ordnung) {
            if ($a->augen() !== $b->augen()) {
                return $a->augen() <=> $b->augen();
            }
            return $ordnung->istTrumpf($a) <=> $ordnung->istTrumpf($b);
        });

        return $karten[0];
    }
}
