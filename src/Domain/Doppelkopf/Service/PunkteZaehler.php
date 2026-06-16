<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;

final class PunkteZaehler
{
    /**
     * Zählt Augen aus einer Kartensammlung.
     * @param Karte[] $karten
     */
    public function augenZaehlen(array $karten): int
    {
        return array_sum(array_map(fn(Karte $k) => $k->augen(), $karten));
    }

    /**
     * Bestimmt das Spielergebnis nach DDV-Grundwertung.
     *
     * RE braucht > 120 (≥ 121) Augen zum Sieg.
     * KONTRA gewinnt mit ≥ 120 Augen (= RE hat ≤ 120).
     *
     * Punktedelta pro Spieler:
     *   RE gewinnt:     RE +1, KONTRA -1
     *   KONTRA gewinnt: KONTRA +2, RE -2  (Unterdog-Bonus)
     *
     * @param int[] $augenProTeam [Team::RE->value => ..., Team::KONTRA->value => ...]
     * @return array{sieger: Team, punkteDelta: array<string, int>}
     */
    public function berechneErgebnis(array $augenProTeam): array
    {
        $reAugen = $augenProTeam[Team::RE->value] ?? 0;
        $reGewonnen = $reAugen >= 121;

        $sieger = $reGewonnen ? Team::RE : Team::KONTRA;

        $punkteDelta = $reGewonnen
            ? [Team::RE->value => 1, Team::KONTRA->value => -1]
            : [Team::RE->value => -2, Team::KONTRA->value => 2];

        return ['sieger' => $sieger, 'punkteDelta' => $punkteDelta];
    }
}
