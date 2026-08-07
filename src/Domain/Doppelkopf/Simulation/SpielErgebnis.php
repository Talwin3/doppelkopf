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

    /**
     * Nur die Absagen (keine 90/60/30/schwarz) dieser Sitzplätze.
     *
     * Getrennt gezählt, weil sie in der Gesamtzahl untergehen: Sie sind der seltene Teil,
     * und ohne diese Zahl lässt sich nicht beurteilen, ob ein gemessener Unterschied
     * überhaupt von ihnen kommen *kann*.
     *
     * @param list<int> $sitzplaetze
     */
    public function absagenVon(array $sitzplaetze): int
    {
        $anzahl = 0;
        foreach ($this->ansagen as $ansage) {
            if (in_array($ansage['sitzplatz'], $sitzplaetze, true)
                && $ansage['typ'] !== AnsageTyp::RE
                && $ansage['typ'] !== AnsageTyp::CONTRA
            ) {
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * Davon aufgegangen: Re/Contra verlangen den Sieg, jede Absage das Unterschreiten ihrer
     * Grenze durch die Gegenpartei.
     *
     * Die **Trefferquote ist die rauschärmere Zielgröße** für Ansage-Varianten. Die
     * Punktdifferenz einer besseren Ansage-Entscheidung liegt schnell unter dem
     * Konfidenzintervall eines bezahlbaren Laufs; ob die Ansagen häufiger halten, zeigt sich
     * dagegen schon bei wenigen hundert Blättern. Sie misst genau das, was eine
     * Rollout-Schätzung besser können soll: die Gewinnchance treffender einschätzen.
     *
     * @param list<int> $sitzplaetze
     */
    public function gehalteneAnsagenVon(array $sitzplaetze): int
    {
        /** @var array<string, int> $stiche */
        $stiche = $this->wertung['stiche'] ?? [];

        $anzahl = 0;
        foreach ($this->ansagen as $ansage) {
            if (!in_array($ansage['sitzplatz'], $sitzplaetze, true)) {
                continue;
            }

            $team = $this->teamProSitzplatz[$ansage['sitzplatz']] ?? null;
            if ($team === null) {
                continue;
            }

            $gegen       = $team === Team::RE ? Team::KONTRA : Team::RE;
            $gegenAugen  = $this->augen[$gegen->value] ?? 0;
            $gegenStiche = $stiche[$gegen->value] ?? 0;

            $gehalten = match ($ansage['typ']) {
                AnsageTyp::RE, AnsageTyp::CONTRA => $this->sieger === $team->value,
                AnsageTyp::KEINE_NEUN            => $gegenAugen < 90,
                AnsageTyp::KEINE_SECHS           => $gegenAugen < 60,
                AnsageTyp::KEINE_DREI            => $gegenAugen < 30,
                AnsageTyp::SCHWARZ               => $gegenStiche === 0,
            };
            if ($gehalten) {
                $anzahl++;
            }
        }

        return $anzahl;
    }
}
