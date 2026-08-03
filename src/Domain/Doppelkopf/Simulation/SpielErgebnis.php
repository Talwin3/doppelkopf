<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Enum\AnsageTyp;
use App\Enum\Team;

/**
 * Ergebnis einer simulierten Partie: die Abrechnung des {@see \App\Domain\Doppelkopf\Service\WertungsRechner}
 * plus die daraus abgeleiteten Turnierpunkte je Sitzplatz.
 */
final class SpielErgebnis
{
    /**
     * @param array<int, int>    $punkteProSitzplatz Sitzplatz → Turnierpunkte (±Spielwert)
     * @param array<int, Team>   $teamProSitzplatz
     * @param array<string, int> $augen              Team-Wert → erspielte Augen
     * @param list<array{sitzplatz: int, typ: AnsageTyp}> $ansagen
     * @param array<string, mixed> $wertung          Volle Abrechnung des WertungsRechners
     */
    public function __construct(
        public readonly array $punkteProSitzplatz,
        public readonly array $teamProSitzplatz,
        public readonly array $augen,
        public readonly ?string $sieger,
        public readonly int $spielwert,
        public readonly array $ansagen,
        public readonly array $wertung,
    ) {}

    /** @param list<int> $sitzplaetze */
    public function punkteFuer(array $sitzplaetze): int
    {
        $summe = 0;
        foreach ($sitzplaetze as $sitz) {
            $summe += $this->punkteProSitzplatz[$sitz] ?? 0;
        }

        return $summe;
    }

    /** Anzahl der Ansagen, die von diesen Sitzplätzen gemacht wurden. @param list<int> $sitzplaetze */
    public function ansagenVon(array $sitzplaetze): int
    {
        $anzahl = 0;
        foreach ($this->ansagen as $ansage) {
            if (in_array($ansage['sitzplatz'], $sitzplaetze, true)) {
                $anzahl++;
            }
        }

        return $anzahl;
    }
}
