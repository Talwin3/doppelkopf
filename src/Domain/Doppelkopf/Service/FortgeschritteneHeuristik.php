<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\FortgeschritteneRegeln;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenwert;
use App\Enum\Team;

/**
 * Stufe {@see \App\Enum\BotStaerke::FORTGESCHRITTEN}: regelbasierte Kartenwahl mit
 * Kartengedächtnis ({@see SpielGedaechtnis}).
 *
 * Gegenüber der {@see BotHeuristik} (Anfänger) zieht diese Engine zusätzlich in Betracht,
 * welche Karten noch im Spiel sind und welche Gegner in welcher Farbe frei sind:
 *  - **Anspielen:** Stiche kassieren, die kein Gegner mehr holen dürfte; Fehlfarben-Ass nur,
 *    wenn kein Gegner in der Farbe frei ist (sonst wird es gestochen); beim Abwerfen Farben
 *    meiden, in denen ein Gegner frei ist.
 *  - **Partner führt:** nur schmieren, wenn der Stich sicher genug ist (letzter Spieler oder
 *    kaum noch zu kippen), sonst sparsam.
 *  - **Gegner führt:** am letzten Platz nur überstechen, wenn es sich lohnt; sonst
 *    augenarm abwerfen.
 *
 * „Sicher genug" heißt hier nicht mehr „gewiss": {@see self::sicherheit()} schätzt, wie
 * wahrscheinlich eine überbietende Karte überhaupt bei jemandem liegt, der noch gefährlich
 * werden kann.
 *
 * Welche dieser Regeln aktiv sind, steuert {@see FortgeschritteneRegeln} – so lässt sich
 * jede einzeln im Self-Play-Benchmark vermessen. Zwei Regeln wurden dabei als schädlich
 * überführt und entfernt: „nur mit unüberbietbarer Karte stechen" (kostete 0,159 Punkte je
 * Partie) und „höchsten Trumpf ziehen" (0,025).
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
     * @param ?FortgeschritteneRegeln $regeln Einzelne Regeln abschaltbar (null = alle an)
     */
    public function entscheide(
        array $erlaubte,
        array $stich,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
        SpielGedaechtnis $gedaechtnis,
        ?FortgeschritteneRegeln $regeln = null,
    ): Karte {
        $erlaubte = array_values($erlaubte);
        if ($erlaubte === []) {
            throw new \InvalidArgumentException('Es muss mindestens eine erlaubte Karte geben.');
        }

        $regeln ??= FortgeschritteneRegeln::alle();

        if ($stich === []) {
            return $this->anspielen($erlaubte, $eigenesTeam, $teams, $ordnung, $gedaechtnis, $regeln);
        }

        return $this->bedienen($erlaubte, $stich, $eigenesTeam, $teams, $ordnung, $gedaechtnis, $regeln);
    }

    /** Bot eröffnet den Stich. */
    private function anspielen(
        array $erlaubte,
        ?Team $eigenesTeam,
        array $teams,
        TrumpfOrdnung $ordnung,
        SpielGedaechtnis $gedaechtnis,
        FortgeschritteneRegeln $regeln,
    ): Karte {
        $fehlfarben = array_values(array_filter($erlaubte, fn(Karte $k) => !$ordnung->istTrumpf($k)));
        $gegnerSitze = $this->gegnerSitze($eigenesTeam, $teams);

        // 1. Sichere Fehlfarbe mit Augen kassieren (kann von niemandem mehr überboten werden).
        if ($regeln->sichereStiche) {
            $sichereMitAugen = array_values(array_filter(
                $fehlfarben,
                fn(Karte $k) => $k->augen() > 0
                    && $this->fehlfarbeSicher($k, $gegnerSitze, $ordnung, $gedaechtnis, $regeln->sicherheitsSchwelle),
            ));
            if ($sichereMitAugen !== []) {
                return $this->hoechsteAugen($sichereMitAugen, $ordnung);
            }
        }

        // 2. Fehlfarben-Ass anspielen – mit Regel nur, wenn kein Gegner in der Farbe frei ist
        //    (sonst wird es gestochen); ohne Regel wie beim Anfänger jedes Ass.
        foreach ($fehlfarben as $k) {
            if ($k->wert !== Kartenwert::ASS) {
                continue;
            }
            if (!$regeln->assNurSicher || !$this->gegnerFreiInFarbe($k, $gegnerSitze, $ordnung, $gedaechtnis)) {
                return $k;
            }
        }

        // 3. Niedrige Fehlfarbe abwerfen – mit Regel bevorzugt in einer Farbe, in der kein
        //    Gegner frei ist.
        if ($fehlfarben !== []) {
            $auswahl = $fehlfarben;
            if ($regeln->sicheresAbwerfen) {
                $ungefaehrlich = array_values(array_filter(
                    $fehlfarben,
                    fn(Karte $k) => !$this->gegnerFreiInFarbe($k, $gegnerSitze, $ordnung, $gedaechtnis),
                ));
                $auswahl = $ungefaehrlich !== [] ? $ungefaehrlich : $fehlfarben;
            }

            return $this->niedrigsteAugen($auswahl, $ordnung);
        }

        // 4. Nur noch Trumpf – niedrigsten spielen.
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
        FortgeschritteneRegeln $regeln,
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
            // Nur schmieren, wenn der Stich für den Partner sicher genug ist. Ohne Regel gilt –
            // wie beim Anfänger – nur der letzte Platz als sicher. Der Partner führt bereits,
            // also sind alle Nachfolger Gegner; wer im Stich schon gespielt hat, zählt nicht.
            $sicher = $istLetzter || ($regeln->schmierSicherheit && $this->sicherheit(
                $this->zaehleUeberbietende($gewinnerKarte, $angespielt, $ordnung, $gedaechtnis),
                3 - count($stich),
            ) >= $regeln->sicherheitsSchwelle);

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
                // Letzter Spieler: sicherer Stich – billigste schlagende Karte nehmen. Mit
                // Regel nur, sofern sich der Stich lohnt; ohne Regel sticht der Anfänger immer.
                if (!$regeln->stichLohntSich || $stichAugen >= self::STECH_SCHWELLE || $this->hatHoheAugen($schlagende)) {
                    return $this->billigsteSchlagende($schlagende, $ordnung);
                }
            } elseif ($stichAugen >= self::STECH_SCHWELLE) {
                // Nicht letzter: billig stechen, sobald genug Augen im Stich liegen. Hier
                // stand einmal die Regel, nur mit einer selbst unüberbietbaren Karte zu
                // stechen – sie hat den Bot 0,159 Punkte je Partie gekostet und ist entfernt.
                return $this->billigsteSchlagende($schlagende, $ordnung);
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
     * Ist diese Fehlfarben-Karte ein sicherer Stich beim Anspielen? Sicher, wenn kein Gegner
     * in der Farbe frei ist (sonst würde gestochen) und die Farbe höchstwahrscheinlich bei
     * keinem Gegner höher liegt.
     *
     * Beim Anspielen kommen noch alle drei Mitspieler dran – gefährlich sind aber nur die
     * beiden Gegner: eine höhere Karte beim **Partner** holt die Augen ebenfalls ins eigene
     * Team. Deshalb zählen nur zwei der drei fremden Hände.
     */
    private function fehlfarbeSicher(
        Karte $karte,
        array $gegnerSitze,
        TrumpfOrdnung $ordnung,
        SpielGedaechtnis $g,
        float $schwelle,
    ): bool {
        if ($this->gegnerFreiInFarbe($karte, $gegnerSitze, $ordnung, $g)) {
            return false;
        }

        $farbe   = $ordnung->fehlfarbe($karte);
        $hoehere = 0;
        foreach ($g->ungesehene() as $u) {
            if (!$ordnung->istTrumpf($u)
                && $ordnung->fehlfarbe($u) === $farbe
                && $ordnung->fehlfarbenRang($u) > $ordnung->fehlfarbenRang($karte)
            ) {
                $hoehere++;
            }
        }

        return $this->sicherheit($hoehere, 2) >= $schwelle;
    }

    /**
     * Wahrscheinlichkeit, dass keine der $hoehere überbietenden Karten in einer der
     * $gefaehrliche fremden Hände liegt.
     *
     * {@see SpielGedaechtnis::ungesehene()} kennt keine Zuordnung zu Sitzen; ohne weiteres
     * Wissen liegt jede ungesehene Karte mit gleicher Wahrscheinlichkeit in jeder der drei
     * fremden Hände. Gefährlich sind je nach Lage nur ein oder zwei davon – die Karten des
     * Partners und die der Spieler, die im Stich schon gespielt haben, können nichts mehr
     * anrichten.
     *
     * Die alte Prüfung „liegt *irgendwo* eine höhere Karte?" entspricht dem Sonderfall
     * `$gefaehrliche = 3` und ist deshalb fast immer negativ ausgefallen.
     */
    private function sicherheit(int $hoehere, int $gefaehrliche): float
    {
        if ($hoehere === 0 || $gefaehrliche <= 0) {
            return 1.0;
        }

        return ((3 - $gefaehrliche) / 3) ** $hoehere;
    }

    /** Wie viele ungesehene Karten schlagen die aktuell führende Karte? */
    private function zaehleUeberbietende(Karte $karte, Karte $angespielt, TrumpfOrdnung $ordnung, SpielGedaechtnis $g): int
    {
        $hoehere = 0;
        foreach ($g->ungesehene() as $u) {
            if ($this->schlaegt($u, $karte, $angespielt, $ordnung)) {
                $hoehere++;
            }
        }

        return $hoehere;
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

}
