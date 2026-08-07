<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\KartenGeber;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Enum\SpielVariante;
use App\Enum\Team;

/**
 * Self-Play-Turnier: lässt zwei Strategien N Blätter gegeneinander spielen und misst den
 * Unterschied in Turnierpunkten.
 *
 * **Gespiegelte Blätter (Duplikat-Verfahren):** Jedes ausgeteilte Blatt wird zweimal
 * gespielt – einmal mit A auf den Sitzen 1/3, einmal mit B auf denselben Sitzen. Beide
 * Strategien bekommen also exakt dieselben Karten in derselben Lage; das Kartenglück
 * kürzt sich heraus und der Vorhand-Vorteil ebenso. Ausgewertet wird die gepaarte
 * Differenz pro Blatt.
 *
 * **Verteilung nach Team statt nach Sitzplatz** ({@see self::spiele()} `$nachTeam`): Die
 * Sitzverteilung oben hat einen Haken, der jede Messung am Ansageverhalten unbrauchbar
 * macht. Die Parteien bilden sich aus den Kreuz-Damen, nicht aus den Sitzen – von den sechs
 * gleich wahrscheinlichen Aufteilungen sind nur {1,3} und {2,4} sortenrein. In den übrigen
 * **zwei Dritteln** sitzt ein A-Bot mit einem B-Bot in einer Partei: Die Ansage des einen
 * kommt dem anderen genauso zugute, und weil die beiden A-Sitze dann in gegnerischen
 * Parteien liegen, heben sich ihre Punkte auf. Solche Blätter tragen zur Differenz nichts
 * bei – eine Ansage-Variante wird also nur auf einem Drittel der Blätter überhaupt gemessen.
 *
 * Im Team-Modus bekommt A die komplette RE-, B die komplette KONTRA-Partei; im Spiegellauf
 * umgekehrt. Damit sitzen die beiden nie zusammen, keine Seite nimmt der anderen die
 * Team-Ansage weg, und der bekannte RE-Vorteil (rund 65 % Siege) kürzt sich über die
 * Spiegelung heraus – genau wie es der Vorhand-Vorteil im Sitz-Modus tut.
 *
 * Der Sitz-Modus bleibt der Default: Er ist der schärfere Test der **Kartenwahl**, weil dort
 * beide Strategien in jeder Partie nebeneinander spielen und sich die Partie-Varianz stärker
 * kürzt. Für alles, was an der Partei hängt – Ansagen, Absagen, Teamzusammenspiel – ist der
 * Team-Modus der richtige.
 *
 * **Seed:** Vor jedem Blatt wird `mt_srand($seed + $blatt)` gesetzt. Da sowohl das
 * Austeilen ({@see KartenGeber}) als auch die Determinisierung der PROFI-Engine über
 * `shuffle()`/`array_rand()` auf den Mersenne-Twister gehen, ist ein Lauf damit
 * reproduzierbar. (Sauberer wäre ein injizierter RNG – siehe Roadmap.)
 */
final class TurnierLauf
{
    public function __construct(
        private readonly SpielSimulator $simulator,
        private readonly KartenGeber $kartenGeber,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
    ) {}

    /**
     * @param array<string, mixed> $regelEinstellungen  Tisch-Regelwerk (ohne_neuner, zweite_dulle_sticht …)
     * @param ?callable(int): void $fortschritt         Wird nach jedem Blatt mit dessen Nummer aufgerufen
     * @param bool $nachTeam A bekommt die ganze RE-, B die ganze KONTRA-Partei (Spiegellauf
     *        umgekehrt), statt fest die Sitze 1/3 bzw. 2/4 – nötig für alles, was an der
     *        Partei hängt. Begründung im Klassenkommentar.
     */
    public function spiele(
        SimulierteStrategie $a,
        SimulierteStrategie $b,
        int $anzahlBlaetter,
        int $seed = 42,
        array $regelEinstellungen = [],
        ?callable $fortschritt = null,
        bool $nachTeam = false,
    ): TurnierErgebnis {
        $ordnung      = $this->trumpfOrdnungFactory->fuer(SpielVariante::NORMALSPIEL);
        $zweiteDulle  = (bool) ($regelEinstellungen['zweite_dulle_sticht'] ?? false);

        $differenzen   = [];
        $uebersprungen = 0;
        $ansagenA      = 0;
        $ansagenB      = 0;
        $gehaltenA     = 0;
        $gehaltenB     = 0;
        $absagenA      = 0;
        $absagenB      = 0;
        $start         = microtime(true);

        for ($blatt = 0; $blatt < $anzahlBlaetter; $blatt++) {
            mt_srand($seed + $blatt);

            $austeilung  = $this->kartenGeber->austeilen($regelEinstellungen);
            $starthaende = [
                1 => $austeilung[0],
                2 => $austeilung[1],
                3 => $austeilung[2],
                4 => $austeilung[3],
            ];

            $teams = SpielSimulator::teamsAusKreuzDamen($starthaende);
            if ($teams === null) {
                // Stille Hochzeit: kein reguläres Normalspiel – Blatt verwerfen.
                $uebersprungen++;
                $fortschritt !== null && $fortschritt($blatt + 1);
                continue;
            }

            // Lauf 1: A auf der einen Seite, Lauf 2 gespiegelt – dieselben Karten.
            // Sitz-Modus: A auf 1/3 bzw. 2/4. Team-Modus: A auf RE bzw. KONTRA.
            [$aSitze, $bSitze] = $nachTeam
                ? $this->parteien($teams)
                : [[1, 3], [2, 4]];

            $lauf1 = $this->simulator->spiele(
                $starthaende,
                $this->zuordnung($aSitze, $a, $bSitze, $b),
                $teams,
                $ordnung,
                $zweiteDulle,
            );
            $lauf2 = $this->simulator->spiele(
                $starthaende,
                $this->zuordnung($aSitze, $b, $bSitze, $a),
                $teams,
                $ordnung,
                $zweiteDulle,
            );

            // 2 A-Spieler je Lauf, 2 Läufe → Ø Turnierpunkte je A-Spieler und Partie.
            $differenzen[] = ($lauf1->punkteFuer($aSitze) + $lauf2->punkteFuer($bSitze)) / 4;

            $ansagenA += $lauf1->ansagenVon($aSitze) + $lauf2->ansagenVon($bSitze);
            $ansagenB += $lauf1->ansagenVon($bSitze) + $lauf2->ansagenVon($aSitze);

            $gehaltenA += $lauf1->gehalteneAnsagenVon($aSitze) + $lauf2->gehalteneAnsagenVon($bSitze);
            $gehaltenB += $lauf1->gehalteneAnsagenVon($bSitze) + $lauf2->gehalteneAnsagenVon($aSitze);

            $absagenA += $lauf1->absagenVon($aSitze) + $lauf2->absagenVon($bSitze);
            $absagenB += $lauf1->absagenVon($bSitze) + $lauf2->absagenVon($aSitze);

            $fortschritt !== null && $fortschritt($blatt + 1);
        }

        return new TurnierErgebnis(
            $a->name(),
            $b->name(),
            $differenzen,
            $uebersprungen,
            $ansagenA,
            $ansagenB,
            microtime(true) - $start,
            $gehaltenA,
            $gehaltenB,
            $absagenA,
            $absagenB,
        );
    }

    /**
     * Die beiden Parteien als Sitzplatzlisten – RE zuerst.
     *
     * @param array<int, Team> $teams
     * @return array{list<int>, list<int>}
     */
    private function parteien(array $teams): array
    {
        $re     = [];
        $kontra = [];
        foreach ($teams as $sitz => $team) {
            $team === Team::RE ? $re[] = $sitz : $kontra[] = $sitz;
        }

        return [$re, $kontra];
    }

    /**
     * @param list<int> $sitzeX
     * @param list<int> $sitzeY
     * @return array<int, SimulierteStrategie>
     */
    private function zuordnung(array $sitzeX, SimulierteStrategie $x, array $sitzeY, SimulierteStrategie $y): array
    {
        $zuordnung = [];
        foreach ($sitzeX as $sitz) {
            $zuordnung[$sitz] = $x;
        }
        foreach ($sitzeY as $sitz) {
            $zuordnung[$sitz] = $y;
        }
        ksort($zuordnung);

        return $zuordnung;
    }
}
