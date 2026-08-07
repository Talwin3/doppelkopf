<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\AnsageTyp;

/**
 * Was eine Ansage nach den Rollouts der PIMC-Engine erwarten lässt – die Schätzung,
 * auf der {@see \App\Domain\Doppelkopf\Service\AnsageHeuristik} ihre Entscheidung baut.
 *
 * Erhoben wird beides, damit sich die zwei Entscheidungsregeln gegeneinander messen lassen
 * ({@see AnsageRollout}):
 *
 * - `$evOhne` / `$evMit`: der Ø signierte Spielwert aus dem produktiven
 *   {@see \App\Domain\Doppelkopf\Service\WertungsRechner}, einmal ohne und einmal mit der
 *   jeweiligen Kandidat-Ansage. Die Differenz ist die eigentlich interessierende Größe –
 *   sie kalibriert sich an unserer eigenen Wertung und zieht Sonderpunkte, „Gegen die
 *   Alten" und schon gefallene Gegner-Ansagen automatisch mit ein.
 * - `$haelt`: der Anteil der Welten, in denen die Ansage aufgeht (bei Re/Contra: die eigene
 *   Partei gewinnt; bei einer Absage: die Gegenpartei bleibt unter der Grenze). Das ist die
 *   Größe, auf die sich die Schwellen des Essener Systems beziehen.
 *
 * ⚠️ **Grenze der Schätzung:** die Rollouts spielen jede Welt mit derselben Greedy-Politik
 * zu Ende, unabhängig davon, ob angesagt wurde. In Wirklichkeit verändert eine Ansage das
 * Spiel beider Seiten – die Gegenpartei kämpft härter, die eigene stellt auf „60 statt 121"
 * um. `$evMit` fällt dadurch systematisch zu optimistisch aus, bei den Absagen stärker als
 * bei Re/Contra.
 */
final readonly class AnsageAussichten
{
    /**
     * @param int                  $welten  Zahl der ausgewerteten Welten (0 = keine Aussage möglich)
     * @param float                $evOhne  Ø signierter Spielwert ohne weitere eigene Ansage
     * @param array<string, float> $evMit   AnsageTyp-Wert → Ø signierter Spielwert mit dieser Ansage
     * @param array<string, float> $haelt   AnsageTyp-Wert → Anteil Welten, in denen sie aufgeht (0…1)
     */
    public function __construct(
        public int $welten,
        public float $evOhne = 0.0,
        public array $evMit = [],
        public array $haelt = [],
    ) {}

    /** Ohne ausgewertete Welten trifft die Heuristik keine Entscheidung. */
    public function leer(): bool
    {
        return $this->welten === 0;
    }

    /** Bringt diese Ansage im Mittel mehr ein, als sie kostet? */
    public function lohnt(AnsageTyp $typ): bool
    {
        $mit = $this->evMit[$typ->value] ?? null;

        return $mit !== null && $mit > $this->evOhne;
    }

    /** Anteil der Welten, in denen die Ansage aufgeht (0…1). */
    public function sicherheit(AnsageTyp $typ): float
    {
        return $this->haelt[$typ->value] ?? 0.0;
    }
}
