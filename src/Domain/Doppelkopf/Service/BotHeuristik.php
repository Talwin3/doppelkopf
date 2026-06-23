<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenwert;
use App\Enum\Team;

/**
 * Regelbasierte Bot-Strategie: wählt aus den erlaubten Karten eine sinnvolle aus.
 *
 * Reine Entscheidungs-Engine ohne Datenbankzugriff — alle nötigen Spielinformationen
 * werden als Parameter übergeben, damit sie isoliert testbar ist. Das Sammeln des
 * aktuellen Stichs und der Teams übernimmt {@see \App\Application\Doppelkopf\BotZugService}.
 *
 * Leitlinien (vereinfachte DDV-Strategie):
 *  - Anspielen: Fehlfarben-Ass (gute Chance auf 11 Augen), sonst niedrig abwerfen, Trumpf sparen.
 *  - Partner führt den Stich: als letzter Spieler schmieren (hohe Augen), sonst sparsam abwerfen.
 *  - Gegner führt: möglichst billig überstechen, wenn es sich lohnt; sonst die augenärmste Karte abwerfen.
 */
final class BotHeuristik
{
    /** Mindest-Augen im Stich, ab denen ein Bot auch ohne Schlusswort sticht. */
    private const STECH_SCHWELLE = 10;

    /**
     * @param Karte[]                 $erlaubte  Spielbare Karten (mind. eine)
     * @param array<int, Karte>       $stich     Sitzplatz → Karte in Spielreihenfolge (leer = Bot spielt an)
     * @param ?Team                   $eigenesTeam Team des Bots (null = unbekannt, z. B. ungelöste Hochzeit)
     * @param array<int, ?Team>       $teams     Sitzplatz → Team aller Mitspieler
     */
    public function entscheide(
        array $erlaubte,
        array $stich,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
    ): Karte {
        $erlaubte = array_values($erlaubte);

        if ($erlaubte === []) {
            throw new \InvalidArgumentException('Es muss mindestens eine erlaubte Karte geben.');
        }

        if ($stich === []) {
            return $this->anspielen($erlaubte, $ordnung);
        }

        return $this->bedienen($erlaubte, $stich, $eigenesTeam, $teams, $ordnung);
    }

    /** Bot eröffnet den Stich. */
    private function anspielen(array $erlaubte, TrumpfOrdnung $ordnung): Karte
    {
        $fehlfarben = array_values(array_filter($erlaubte, fn(Karte $k) => !$ordnung->istTrumpf($k)));

        // Fehlfarben-Ass anspielen: hohe Chance, 11 Augen sicher einzufahren.
        foreach ($fehlfarben as $k) {
            if ($k->wert === Kartenwert::ASS) {
                return $k;
            }
        }

        // Sonst eine niedrige Fehlfarbe abwerfen und Trumpf für später behalten.
        if ($fehlfarben !== []) {
            return $this->niedrigsteAugen($fehlfarben, $ordnung);
        }

        // Nur noch Trumpf auf der Hand → den niedrigsten Trumpf spielen.
        return $this->niedrigsterTrumpf($erlaubte, $ordnung);
    }

    /** Bot bedient einen bereits eröffneten Stich. */
    private function bedienen(
        array $erlaubte,
        array $stich,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
    ): Karte {
        $angespielt = array_values($stich)[0];

        // Aktuell führende Karte (und deren Sitzplatz) ermitteln.
        $gewinnerSitz  = array_key_first($stich);
        $gewinnerKarte = $stich[$gewinnerSitz];
        foreach ($stich as $sitz => $karte) {
            if ($this->schlaegt($karte, $gewinnerKarte, $angespielt, $ordnung)) {
                $gewinnerSitz  = $sitz;
                $gewinnerKarte = $karte;
            }
        }

        $gewinnerTeam  = $teams[$gewinnerSitz] ?? null;
        $partnerFuehrt = $eigenesTeam !== null && $gewinnerTeam !== null && $eigenesTeam === $gewinnerTeam;
        $istLetzter    = count($stich) === 3;

        if ($partnerFuehrt) {
            // Partner gewinnt den Stich: als Letzter schmieren wir hohe Augen, sonst
            // werfen wir sparsam ab (weitere Gegner könnten den Stich noch kippen).
            return $istLetzter
                ? $this->hoechsteAugen($erlaubte, $ordnung)
                : $this->niedrigsteAugen($erlaubte, $ordnung);
        }

        // Gegner führt (oder Lage unklar): können wir überstechen?
        $schlagende = array_values(array_filter(
            $erlaubte,
            fn(Karte $k) => $this->schlaegt($k, $gewinnerKarte, $angespielt, $ordnung),
        ));

        if ($schlagende !== []) {
            $stichAugen = array_sum(array_map(fn(Karte $k) => $k->augen(), $stich));

            // Als Letzter sticht der Bot immer (sicherer Stich); sonst nur, wenn genug
            // Augen im Spiel sind — sonst verschwendet er hohen Trumpf.
            if ($istLetzter || $stichAugen >= self::STECH_SCHWELLE) {
                return $this->billigsteSchlagende($schlagende, $ordnung);
            }
        }

        // Nicht stechen (können/wollen) → so wenig Augen wie möglich abgeben.
        return $this->niedrigsteAugen($erlaubte, $ordnung);
    }

    /**
     * Schlägt $kandidat die aktuell führende Karte $gewinner? Spiegelt die
     * paarweise Vergleichslogik aus {@see StichGewinner}.
     */
    private function schlaegt(Karte $kandidat, Karte $gewinner, Karte $angespielt, TrumpfOrdnung $ordnung): bool
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

        // Beide sind Nicht-Trumpf: nur Bedienen der Anspielfarbe kann stechen.
        if ($ordnung->istTrumpf($angespielt)) {
            return false;
        }
        if ($ordnung->fehlfarbe($kandidat) !== $ordnung->fehlfarbe($angespielt)) {
            return false;
        }

        return $ordnung->fehlfarbenRang($kandidat) > $ordnung->fehlfarbenRang($gewinner);
    }

    /** Billigste Karte, die sticht: Nicht-Trumpf vor Trumpf, dann niedrigster Rang. */
    private function billigsteSchlagende(array $schlagende, TrumpfOrdnung $ordnung): Karte
    {
        usort($schlagende, function (Karte $a, Karte $b) use ($ordnung) {
            $aTrumpf = $ordnung->istTrumpf($a);
            $bTrumpf = $ordnung->istTrumpf($b);
            if ($aTrumpf !== $bTrumpf) {
                return $aTrumpf <=> $bTrumpf; // false (0) vor true (1) → Nicht-Trumpf zuerst
            }
            $aRang = $aTrumpf ? $ordnung->trumpfRang($a) : $ordnung->fehlfarbenRang($a);
            $bRang = $bTrumpf ? $ordnung->trumpfRang($b) : $ordnung->fehlfarbenRang($b);
            return $aRang <=> $bRang;
        });

        return $schlagende[0];
    }

    /** Höchste Augen (zum Schmieren); bei Gleichstand Nicht-Trumpf bevorzugt (Trumpf sparen). */
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

    /** Niedrigste Augen (zum Abwerfen); bei Gleichstand Nicht-Trumpf bevorzugt (Trumpf sparen). */
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

    /** Niedrigster Trumpf der Auswahl. */
    private function niedrigsterTrumpf(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, fn(Karte $a, Karte $b) => $ordnung->trumpfRang($a) <=> $ordnung->trumpfRang($b));

        return $karten[0];
    }
}
