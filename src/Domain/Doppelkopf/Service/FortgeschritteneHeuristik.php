<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\Team;

/**
 * Stufe {@see \App\Enum\BotStaerke::FORTGESCHRITTEN}: regelbasierte Kartenwahl mit
 * Kartengedächtnis ({@see SpielGedaechtnis}).
 *
 * Gegenüber der {@see BotHeuristik} (Anfänger) zieht diese Engine zusätzlich in Betracht,
 * welche Karten noch im Spiel sind und welche Gegner in welcher Farbe frei sind:
 *  - **Anspielen:** sichere Stiche kassieren (Karte, die niemand mehr überbieten kann);
 *    Fehlfarben-Ass nur, wenn kein Gegner in der Farbe frei ist (sonst wird es gestochen);
 *    hohen Trumpf ziehen, wenn man den höchsten hält.
 *  - **Partner führt:** nur schmieren, wenn der Stich sicher ist (letzter Spieler oder
 *    unschlagbare Karte), sonst sparsam.
 *  - **Gegner führt:** nur überstechen, wenn es sich lohnt und nicht selbst überstochen
 *    werden kann; sonst augenarm abwerfen.
 *
 * Reine Logik ohne Datenbankzugriff; das Sammeln des Verlaufs übernimmt
 * {@see \App\Application\Doppelkopf\Bot\FortgeschritteneStrategie}.
 */
final class FortgeschritteneHeuristik
{
    /** Mindest-Augen im Stich, ab denen ein sicherer Stich auch mit Trumpf geholt wird. */
    private const STECH_SCHWELLE = 10;

    /**
     * @param Karte[]           $erlaubte  Spielbare Karten (mind. eine)
     * @param array<int, Karte> $stich     Sitzplatz → Karte in Spielreihenfolge (leer = Bot spielt an)
     * @param ?Team             $eigenesTeam Team des Bots (null = unbekannt)
     * @param array<int, ?Team> $teams     Sitzplatz → Team aller Mitspieler
     */
    public function entscheide(
        array $erlaubte,
        array $stich,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
        SpielGedaechtnis $gedaechtnis,
    ): Karte {
        $erlaubte = array_values($erlaubte);
        if ($erlaubte === []) {
            throw new \InvalidArgumentException('Es muss mindestens eine erlaubte Karte geben.');
        }

        if ($stich === []) {
            return $this->anspielen($erlaubte, $eigenesTeam, $teams, $ordnung, $gedaechtnis);
        }

        return $this->bedienen($erlaubte, $stich, $eigenesTeam, $teams, $ordnung, $gedaechtnis);
    }

    /** Bot eröffnet den Stich. */
    private function anspielen(
        array $erlaubte,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
        SpielGedaechtnis $gedaechtnis,
    ): Karte {
        $fehlfarben = array_values(array_filter($erlaubte, fn(Karte $k) => !$ordnung->istTrumpf($k)));
        $gegnerSitze = $this->gegnerSitze($eigenesTeam, $teams);

        // 1. Sichere Fehlfarbe mit Augen kassieren (kann von niemandem mehr überboten werden).
        $sichereMitAugen = array_values(array_filter(
            $fehlfarben,
            fn(Karte $k) => $k->augen() > 0 && $this->fehlfarbeSicher($k, $gegnerSitze, $ordnung, $gedaechtnis),
        ));
        if ($sichereMitAugen !== []) {
            return $this->hoechsteAugen($sichereMitAugen, $ordnung);
        }

        // 2. Fehlfarben-Ass nur anspielen, wenn kein Gegner in der Farbe frei ist (sonst gestochen).
        foreach ($fehlfarben as $k) {
            if ($k->wert === Kartenwert::ASS && !$this->gegnerFreiInFarbe($k, $gegnerSitze, $ordnung, $gedaechtnis)) {
                return $k;
            }
        }

        // 3. Höchsten Trumpf ziehen, wenn er sicher ist und Gegner noch Trumpf haben.
        $truempfe = array_values(array_filter($erlaubte, fn(Karte $k) => $ordnung->istTrumpf($k)));
        if (count($truempfe) >= 2) {
            $hoechster = $this->hoechsterTrumpf($truempfe, $ordnung);
            if ($this->istHoechsterTrumpfImSpiel($hoechster, $ordnung, $gedaechtnis)
                && $this->gegnerHabenTrumpf($ordnung, $gedaechtnis)
            ) {
                return $hoechster;
            }
        }

        // 4. Niedrige Fehlfarbe abwerfen – bevorzugt in einer Farbe, in der kein Gegner frei ist.
        if ($fehlfarben !== []) {
            $ungefaehrlich = array_values(array_filter(
                $fehlfarben,
                fn(Karte $k) => !$this->gegnerFreiInFarbe($k, $gegnerSitze, $ordnung, $gedaechtnis),
            ));

            return $this->niedrigsteAugen($ungefaehrlich !== [] ? $ungefaehrlich : $fehlfarben, $ordnung);
        }

        // 5. Nur noch Trumpf – niedrigsten spielen.
        return $this->niedrigsterTrumpf($erlaubte, $ordnung);
    }

    /** Bot bedient einen bereits eröffneten Stich. */
    private function bedienen(
        array $erlaubte,
        array $stich,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
        SpielGedaechtnis $gedaechtnis,
    ): Karte {
        $angespielt = array_values($stich)[0];

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
        $stichAugen    = array_sum(array_map(fn(Karte $k) => $k->augen(), $stich));

        if ($partnerFuehrt) {
            // Nur schmieren, wenn der Stich für den Partner sicher ist.
            $sicher = $istLetzter || !$this->kannUeberbotenWerden($gewinnerKarte, $angespielt, $ordnung, $gedaechtnis);

            return $sicher
                ? $this->hoechsteAugen($erlaubte, $ordnung)
                : $this->niedrigsteAugen($erlaubte, $ordnung);
        }

        // Gegner führt: können wir überstechen?
        $schlagende = array_values(array_filter(
            $erlaubte,
            fn(Karte $k) => $this->schlaegt($k, $gewinnerKarte, $angespielt, $ordnung),
        ));

        if ($schlagende !== []) {
            if ($istLetzter) {
                // Letzter Spieler: sicherer Stich – billigste schlagende Karte nehmen,
                // sofern sich der Stich lohnt.
                if ($stichAugen >= self::STECH_SCHWELLE || $this->hatHoheAugen($schlagende)) {
                    return $this->billigsteSchlagende($schlagende, $ordnung);
                }
            } else {
                // Nicht letzter: nur mit einer Karte stechen, die selbst nicht mehr
                // überboten werden kann (sonst verpufft der hohe Trumpf).
                $unschlagbar = array_values(array_filter(
                    $schlagende,
                    fn(Karte $k) => !$this->kannUeberbotenWerden($k, $angespielt, $ordnung, $gedaechtnis),
                ));
                if ($unschlagbar !== [] && $stichAugen >= self::STECH_SCHWELLE) {
                    return $this->billigsteSchlagende($unschlagbar, $ordnung);
                }
            }
        }

        // Nicht stechen (können/wollen) → so wenig Augen wie möglich abgeben.
        return $this->niedrigsteAugen($erlaubte, $ordnung);
    }

    // ── Gedächtnis-gestützte Prüfungen ─────────────────────────────────────

    /** Sitzplätze der Gegner (Team != eigenes). Bei unbekanntem Team: leere Liste (keine Annahmen). */
    private function gegnerSitze(?Team $eigenesTeam, array $teams): array
    {
        if ($eigenesTeam === null) {
            return [];
        }

        $sitze = [];
        foreach ($teams as $sitz => $team) {
            if ($team !== null && $team !== $eigenesTeam) {
                $sitze[] = $sitz;
            }
        }

        return $sitze;
    }

    /** Ist ein Gegner in der Farbe dieser (Nicht-Trumpf-)Karte frei? */
    private function gegnerFreiInFarbe(Karte $karte, array $gegnerSitze, TrumpfOrdnung $ordnung, SpielGedaechtnis $g): bool
    {
        $farbe = $ordnung->fehlfarbe($karte);
        if ($farbe === null) {
            return false;
        }

        foreach ($gegnerSitze as $sitz) {
            if ($g->istFrei($sitz, $farbe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ist diese Fehlfarben-Karte ein sicherer Stich beim Anspielen? Sicher, wenn keine
     * ungesehene Karte derselben Farbe höher ist UND kein Gegner in der Farbe frei ist
     * (sonst könnte gestochen werden).
     */
    private function fehlfarbeSicher(Karte $karte, array $gegnerSitze, TrumpfOrdnung $ordnung, SpielGedaechtnis $g): bool
    {
        if ($this->gegnerFreiInFarbe($karte, $gegnerSitze, $ordnung, $g)) {
            return false;
        }

        $farbe = $ordnung->fehlfarbe($karte);
        foreach ($g->ungesehene() as $u) {
            if (!$ordnung->istTrumpf($u)
                && $ordnung->fehlfarbe($u) === $farbe
                && $ordnung->fehlfarbenRang($u) > $ordnung->fehlfarbenRang($karte)
            ) {
                return false;
            }
        }

        return true;
    }

    /** Kann die (aktuell führende/kandidierende) Karte von einer ungesehenen Karte überboten werden? */
    private function kannUeberbotenWerden(Karte $karte, Karte $angespielt, TrumpfOrdnung $ordnung, SpielGedaechtnis $g): bool
    {
        foreach ($g->ungesehene() as $u) {
            if ($this->schlaegt($u, $karte, $angespielt, $ordnung)) {
                return true;
            }
        }

        return false;
    }

    /** Ist dieser Trumpf der höchste noch im Spiel befindliche (kein ungesehener Trumpf höher)? */
    private function istHoechsterTrumpfImSpiel(Karte $trumpf, TrumpfOrdnung $ordnung, SpielGedaechtnis $g): bool
    {
        foreach ($g->ungesehene() as $u) {
            if ($ordnung->istTrumpf($u) && $ordnung->trumpfRang($u) > $ordnung->trumpfRang($trumpf)) {
                return false;
            }
        }

        return true;
    }

    /** Halten die Gegner/andere Spieler überhaupt noch Trumpf? */
    private function gegnerHabenTrumpf(TrumpfOrdnung $ordnung, SpielGedaechtnis $g): bool
    {
        foreach ($g->ungesehene() as $u) {
            if ($ordnung->istTrumpf($u)) {
                return true;
            }
        }

        return false;
    }

    private function hatHoheAugen(array $karten): bool
    {
        foreach ($karten as $k) {
            if ($k->augen() >= 10) {
                return true;
            }
        }

        return false;
    }

    // ── Vergleichs- und Sortier-Helfer (analog BotHeuristik) ────────────────

    /** Spiegelt die paarweise Vergleichslogik aus {@see StichGewinner}. */
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

        if ($ordnung->istTrumpf($angespielt)) {
            return false;
        }
        if ($ordnung->fehlfarbe($kandidat) !== $ordnung->fehlfarbe($angespielt)) {
            return false;
        }

        return $ordnung->fehlfarbenRang($kandidat) > $ordnung->fehlfarbenRang($gewinner);
    }

    private function billigsteSchlagende(array $schlagende, TrumpfOrdnung $ordnung): Karte
    {
        usort($schlagende, function (Karte $a, Karte $b) use ($ordnung) {
            $aTrumpf = $ordnung->istTrumpf($a);
            $bTrumpf = $ordnung->istTrumpf($b);
            if ($aTrumpf !== $bTrumpf) {
                return $aTrumpf <=> $bTrumpf; // Nicht-Trumpf zuerst
            }
            $aRang = $aTrumpf ? $ordnung->trumpfRang($a) : $ordnung->fehlfarbenRang($a);
            $bRang = $bTrumpf ? $ordnung->trumpfRang($b) : $ordnung->fehlfarbenRang($b);
            return $aRang <=> $bRang;
        });

        return $schlagende[0];
    }

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

    private function niedrigsterTrumpf(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, fn(Karte $a, Karte $b) => $ordnung->trumpfRang($a) <=> $ordnung->trumpfRang($b));

        return $karten[0];
    }

    private function hoechsterTrumpf(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, fn(Karte $a, Karte $b) => $ordnung->trumpfRang($b) <=> $ordnung->trumpfRang($a));

        return $karten[0];
    }
}
