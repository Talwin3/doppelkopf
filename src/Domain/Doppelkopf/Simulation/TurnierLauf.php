<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\KartenGeber;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Enum\SpielVariante;

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
     */
    public function spiele(
        SimulierteStrategie $a,
        SimulierteStrategie $b,
        int $anzahlBlaetter,
        int $seed = 42,
        array $regelEinstellungen = [],
        ?callable $fortschritt = null,
    ): TurnierErgebnis {
        $ordnung      = $this->trumpfOrdnungFactory->fuer(SpielVariante::NORMALSPIEL);
        $zweiteDulle  = (bool) ($regelEinstellungen['zweite_dulle_sticht'] ?? false);

        $differenzen   = [];
        $uebersprungen = 0;
        $ansagenA      = 0;
        $ansagenB      = 0;
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

            // Lauf 1: A sitzt auf 1/3 – Lauf 2: A sitzt auf 2/4 (dieselben Karten).
            $lauf1 = $this->simulator->spiele(
                $starthaende,
                [1 => $a, 2 => $b, 3 => $a, 4 => $b],
                $teams,
                $ordnung,
                $zweiteDulle,
            );
            $lauf2 = $this->simulator->spiele(
                $starthaende,
                [1 => $b, 2 => $a, 3 => $b, 4 => $a],
                $teams,
                $ordnung,
                $zweiteDulle,
            );

            // 2 A-Spieler je Lauf, 2 Läufe → Ø Turnierpunkte je A-Spieler und Partie.
            $differenzen[] = ($lauf1->punkteFuer([1, 3]) + $lauf2->punkteFuer([2, 4])) / 4;

            $ansagenA += $lauf1->ansagenVon([1, 3]) + $lauf2->ansagenVon([2, 4]);
            $ansagenB += $lauf1->ansagenVon([2, 4]) + $lauf2->ansagenVon([1, 3]);

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
        );
    }
}
