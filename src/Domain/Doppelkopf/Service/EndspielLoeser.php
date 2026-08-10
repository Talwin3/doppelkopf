<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\EndspielPolitik;
use App\Enum\Team;

/**
 * Löst das Endspiel einer determinisierten Welt **exakt** statt es zu schätzen.
 *
 * Hintergrund: Innerhalb einer Welt der PIMC-Determinisierung sind alle Karten bekannt –
 * Doppelkopf ist dort ein Spiel mit **perfekter Information**, und die beste Linie muss nicht
 * geraten werden, sie lässt sich ausrechnen. Die {@see ProfiHeuristik} spielt die Welt heute
 * mit einem einzigen Greedy-Durchlauf zu Ende ({@see ProfiHeuristik::ausspielen}); das ist eine
 * grobe Schätzung ohne jedes Zurücknehmen.
 *
 * Diese Klasse ersetzt sie im Endspiel durch eine Suche mit Alpha-Beta. Beide Spieler einer
 * Partei ziehen am selben Strang (im Rollout sind die Parteien bekannt), also maximieren die
 * Sitze des Zielteams und die beiden anderen minimieren.
 *
 * **Wer wirklich sucht, steuert die {@see EndspielPolitik}.** „Exakt ausgerechnet" ist ein
 * Ergebnis immer nur unter einer Annahme über das Spiel der anderen drei. Unterstellt man
 * ihnen Minimax, verteidigt der Löser gegen einen Gegner, der am Tisch gar nicht sitzt – dort
 * spielt die {@see RolloutPolitik}. Die Alternativen lassen die nicht gesuchten Sitze deshalb
 * genau diese Greedy-Politik spielen; ihr Zug ist dann erzwungen, der Baum verzweigt nur noch
 * an ein bis zwei Sitzen.
 *
 * Anlass ist der Befund von Sievers/Helmert, dass bei festem Budget *wenige Welten mit viel
 * Suche* deutlich besser abschneiden als viele Welten ohne Suche (dort 10 × 1000 gegen 20 × 500
 * und 5 × 2000) – und unser eigener Nullbefund, dass mehr Welten allein nichts bringen (P5.3).
 *
 * ⚠️ Bewertet wird in **Augen**, dem heutigen Default-Maßstab der Engine. Der Löser liefert
 * deshalb nur den Wert, nicht die gespielte Linie; für die (per Default abgeschaltete)
 * Punkte-Bewertung bleibt es beim Greedy-Rollout, der die Stiche einzeln braucht.
 *
 * Reine Logik ohne DB.
 */
final class EndspielLoeser
{
    /** Ab hier lohnt die Zugsortierung den Aufwand nicht mehr (letzter Stich ist erzwungen). */
    private const SORTIER_SCHWELLE = 2;

    public function __construct(
        private readonly StichGewinner $stichGewinner,
        private readonly RolloutPolitik $rolloutPolitik,
    ) {}

    /**
     * Augen, die `$zielTeam` aus der Reststellung holt, wenn die suchenden Sitze optimal und
     * alle übrigen nach der {@see RolloutPolitik} spielen.
     *
     * @param array<int, Karte[]> $haende      Sitzplatz → Handkarten (alle vier Sitze)
     * @param array<int, Karte>   $stich       Sitzplatz → Karte des laufenden Stichs, in Spielreihenfolge
     * @param int                 $amZug       Sitzplatz, der als Nächstes legt
     * @param array<int, Team>    $teams       Sitzplatz → Partei (in dieser Welt vollständig bekannt)
     * @param int|null            $eigenerSitz Sitz des entscheidenden Bots – nur für
     *        {@see EndspielPolitik::NUR_ICH} nötig, sonst ohne Bedeutung
     */
    public function augen(
        array $haende,
        array $stich,
        int $amZug,
        array $teams,
        Team $zielTeam,
        TrumpfOrdnung $ordnung,
        bool $zweiteDulle,
        EndspielPolitik $politik = EndspielPolitik::MINIMAX,
        ?int $eigenerSitz = null,
    ): int {
        if ($politik === EndspielPolitik::NUR_ICH && $eigenerSitz === null) {
            throw new \InvalidArgumentException('Die Politik "nur-ich" braucht den eigenen Sitzplatz.');
        }

        $suchend = [];
        for ($sitz = 1; $sitz <= 4; $sitz++) {
            $suchend[$sitz] = match ($politik) {
                EndspielPolitik::MINIMAX       => true,
                EndspielPolitik::GEGNER_GREEDY => ($teams[$sitz] ?? null) === $zielTeam,
                EndspielPolitik::NUR_ICH       => $sitz === $eigenerSitz,
            };
        }

        return $this->suche($haende, $stich, $amZug, $teams, $zielTeam, $ordnung, $zweiteDulle, $suchend, PHP_INT_MIN, PHP_INT_MAX);
    }

    /**
     * Alpha-Beta über die Reststellung; nicht suchende Sitze ziehen erzwungen greedy.
     *
     * @param array<int, Karte[]> $haende
     * @param array<int, Karte>   $stich
     * @param array<int, Team>    $teams
     * @param array<int, bool>    $suchend Sitzplatz → sucht dieser Sitz seinen besten Zug?
     */
    private function suche(
        array $haende,
        array $stich,
        int $amZug,
        array $teams,
        Team $ziel,
        TrumpfOrdnung $ordnung,
        bool $zweiteDulle,
        array $suchend,
        int $alpha,
        int $beta,
    ): int {
        if (count($stich) === 4) {
            $gewinner = $this->stichGewinner->bestimme($stich, $ordnung, $zweiteDulle);

            $ausbeute = 0;
            if (($teams[$gewinner] ?? null) === $ziel) {
                foreach ($stich as $karte) {
                    $ausbeute += $karte->augen();
                }
            }

            // Der Stich ist abgerechnet und von der Fortsetzung unabhängig; das Suchfenster
            // wandert deshalb um genau diesen Betrag mit.
            $rest = $this->alleLeer($haende)
                ? 0
                : $this->suche(
                    $haende,
                    [],
                    $gewinner,
                    $teams,
                    $ziel,
                    $ordnung,
                    $zweiteDulle,
                    $suchend,
                    $this->verschiebe($alpha, -$ausbeute),
                    $this->verschiebe($beta, -$ausbeute),
                );

            return $ausbeute + $rest;
        }

        $hand = $haende[$amZug] ?? [];
        if ($hand === []) {
            return 0; // Sicherheitsnetz – kann bei konsistenten Händen nicht eintreten
        }

        $angespielt = $stich === [] ? null : array_values($stich)[0];
        $naechster  = ($amZug % 4) + 1;

        // Sitz mit fremder Politik: sein Zug steht fest, es gibt nichts zu verzweigen.
        if (!($suchend[$amZug] ?? true)) {
            $karte = $this->rolloutPolitik->waehle($hand, $stich, $teams[$amZug], $teams, $angespielt, $ordnung);

            $folgeHaende         = $haende;
            $folgeHaende[$amZug] = $this->ohne($hand, $karte);
            $folgeStich          = $stich;
            $folgeStich[$amZug]  = $karte;

            return $this->suche($folgeHaende, $folgeStich, $naechster, $teams, $ziel, $ordnung, $zweiteDulle, $suchend, $alpha, $beta);
        }

        $zuege = $this->zuege($hand, $stich, $angespielt, $ordnung, $teams[$amZug] ?? null, $ziel);

        if ($zuege === []) {
            return 0; // Sicherheitsnetz – kann bei konsistenten Händen nicht eintreten
        }

        $maximiert = ($teams[$amZug] ?? null) === $ziel;
        $bester    = $maximiert ? PHP_INT_MIN : PHP_INT_MAX;

        foreach ($zuege as $karte) {
            $folgeHaende          = $haende;
            $folgeHaende[$amZug]  = $this->ohne($hand, $karte);
            $folgeStich           = $stich;
            $folgeStich[$amZug]   = $karte;

            $wert = $this->suche($folgeHaende, $folgeStich, $naechster, $teams, $ziel, $ordnung, $zweiteDulle, $suchend, $alpha, $beta);

            if ($maximiert) {
                if ($wert > $bester) {
                    $bester = $wert;
                }
                if ($bester > $alpha) {
                    $alpha = $bester;
                }
            } else {
                if ($wert < $bester) {
                    $bester = $wert;
                }
                if ($bester < $beta) {
                    $beta = $bester;
                }
            }

            if ($beta <= $alpha) {
                break; // der Gegenzug wird nie zugelassen
            }
        }

        return $bester;
    }

    /**
     * Zugkandidaten: legale Karten, **entdoppelt** und grob vorsortiert.
     *
     * Das Entdoppeln ist der wirksamste Einzelschritt: im Doppelkopf-Blatt liegt jede Karte
     * zweimal, und zwei gleiche Karten auf einer Hand sind derselbe Zug. Ohne diesen Schritt
     * durchsucht Alpha-Beta identische Teilbäume mehrfach.
     *
     * @param Karte[]           $hand
     * @param array<int, Karte> $stich
     *
     * @return list<Karte>
     */
    private function zuege(array $hand, array $stich, ?Karte $angespielt, TrumpfOrdnung $ordnung, ?Team $eigenes, Team $ziel): array
    {
        $legale = LegaleKarten::fuer($hand, $angespielt, $ordnung);

        $einmalig = [];
        foreach ($legale as $karte) {
            $einmalig[$karte->id()] = $karte;
        }
        $zuege = array_values($einmalig);

        if (count($zuege) < self::SORTIER_SCHWELLE) {
            return $zuege;
        }

        // Wer maximiert, probiert die stärkste Karte zuerst; wer minimiert, die schwächste.
        // Frühe gute Züge erzeugen die Schnitte, von denen Alpha-Beta lebt.
        $absteigend = $eigenes === $ziel;
        usort($zuege, function (Karte $a, Karte $b) use ($ordnung, $angespielt, $absteigend): int {
            $vergleich = $this->rang($a, $angespielt, $ordnung) <=> $this->rang($b, $angespielt, $ordnung);

            return $absteigend ? -$vergleich : $vergleich;
        });

        return $zuege;
    }

    /**
     * Grobe Stärke einer Karte im laufenden Stich – Trümpfe über allem, dann die bediente
     * Farbe, alles andere kann den Stich nicht holen und wird nach Augen gereiht.
     */
    private function rang(Karte $karte, ?Karte $angespielt, TrumpfOrdnung $ordnung): int
    {
        if ($ordnung->istTrumpf($karte)) {
            return 2000 + $ordnung->trumpfRang($karte);
        }

        $bedient = $angespielt !== null
            && !$ordnung->istTrumpf($angespielt)
            && $ordnung->fehlfarbe($karte) === $ordnung->fehlfarbe($angespielt);

        return ($bedient ? 1000 : 0) + $ordnung->fehlfarbenRang($karte);
    }

    /** @param Karte[] $karten */
    private function ohne(array $karten, Karte $entfernen): array
    {
        $raus = false;
        $rest = [];
        foreach ($karten as $k) {
            if (!$raus && $k->id() === $entfernen->id()) {
                $raus = true;
                continue;
            }
            $rest[] = $k;
        }

        return $rest;
    }

    /** @param array<int, Karte[]> $haende */
    private function alleLeer(array $haende): bool
    {
        foreach ($haende as $hand) {
            if ($hand !== []) {
                return false;
            }
        }

        return true;
    }

    /** Verschiebt eine Fensterschranke, ohne die Unendlich-Marken überlaufen zu lassen. */
    private function verschiebe(int $schranke, int $delta): int
    {
        if ($schranke === PHP_INT_MIN || $schranke === PHP_INT_MAX) {
            return $schranke;
        }

        return $schranke + $delta;
    }
}
