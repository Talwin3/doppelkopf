<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Welches Gegenspiel der {@see \App\Domain\Doppelkopf\Service\EndspielLoeser} unterstellt.
 *
 * Der Löser rechnet die Reststellung einer determinisierten Welt exakt aus. „Exakt" heißt
 * dabei immer: exakt **unter einer Annahme darüber, wie die anderen drei spielen**. Bisher
 * war das Minimax – alle vier Sitze ziehen die für ihre Partei beste Linie.
 *
 * Genau diese Annahme ist der gemessene Verdachtsfall (2026-08-09): der Löser greift in
 * 14,7 % der Züge und wählt dort in 22,3 % eine andere Karte als der Greedy-Rollout – ohne
 * dass die Spielstärke sich bewegt. Die Eingriffe heben sich also auf. Die naheliegende
 * Ursache (auch von Sievers/Helmert als offene Schwäche des Ansatzes benannt): der Löser
 * verteidigt gegen einen optimalen Gegner, am Tisch sitzt aber einer, der nach der
 * Greedy-Politik des Rollouts spielt. Eine Linie, die nur gegen perfektes Gegenspiel die
 * beste ist, kann gegen den tatsächlichen Gegner schlechter sein.
 *
 * Die Alternativen unterstellen deshalb den Sitzen, die man nicht selbst steuert, dieselbe
 * {@see \App\Domain\Doppelkopf\Service\RolloutPolitik}, die auch der Greedy-Rollout spielt –
 * der Löser sucht dann die beste Antwort *auf den wirklichen Gegner* statt auf einen idealen.
 * Nebeneffekt: es verzweigen nur noch ein bis zwei statt vier Sitze, der Baum wird deutlich
 * kleiner.
 */
enum EndspielPolitik: string
{
    /** Alle vier Sitze suchen optimal (Stand bis 2026-08-09). */
    case MINIMAX = 'minimax';

    /** Nur die eigene Partei sucht; die Gegenpartei spielt die Greedy-Politik des Rollouts. */
    case GEGNER_GREEDY = 'gegner-greedy';

    /**
     * Nur der entscheidende Sitz sucht; auch der **Partner** spielt greedy.
     *
     * Konsequenteste Fassung: der Partner kennt die fremden Hände in Wahrheit ebenso wenig
     * wie die Gegner und kann deshalb gar nicht optimal mitziehen. Eine Linie, die auf einen
     * hellsichtig kooperierenden Partner baut, ist dieselbe Sorte Fehlannahme wie die auf
     * einen hellsichtigen Gegner.
     */
    case NUR_ICH = 'nur-ich';

    public static function default(): self
    {
        return self::MINIMAX;
    }

    public static function vonWert(?string $wert): self
    {
        if ($wert === null || $wert === '') {
            return self::default();
        }

        return self::tryFrom($wert) ?? throw new \InvalidArgumentException(sprintf(
            'Unbekannte Endspiel-Politik "%s". Erlaubt: %s',
            $wert,
            implode(', ', array_map(static fn(self $p) => $p->value, self::cases())),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::MINIMAX       => 'Minimax (alle vier Sitze optimal)',
            self::GEGNER_GREEDY => 'Gegner greedy',
            self::NUR_ICH       => 'nur der eigene Sitz sucht',
        };
    }
}
