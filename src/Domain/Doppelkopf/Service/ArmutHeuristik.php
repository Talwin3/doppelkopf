<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\BotStaerke;
use App\Enum\Kartenwert;

/**
 * Bot-Entscheidungen zur Armut – reine Logik ohne DB.
 *
 *  - {@see self::willAnnehmen()}: Nimmt der Bot die Armut an? Anfänger ab 5 Trümpfen;
 *    stärkere Bots zusätzlich bei 4 Trümpfen mit vielen hohen Trümpfen (Damen/Buben).
 *  - {@see self::kartenZurueckgeben()}: Welche Karten gibt der Annehmer zurück?
 *    Anfänger geben die augenärmsten Nicht-Trümpfe (dann Trümpfe) ab. Stärkere Bots
 *    räumen bevorzugt eine schwache Fehlfarbe komplett ab (Frei-Werden → später
 *    stechen), bevor sie mit augenarmen Karten auffüllen.
 */
final class ArmutHeuristik
{
    /**
     * @param Karte[] $hand
     */
    public function willAnnehmen(BotStaerke $staerke, array $hand, TrumpfOrdnung $ordnung): bool
    {
        $truempfe     = 0;
        $hoheTruempfe = 0;
        foreach ($hand as $k) {
            if ($ordnung->istTrumpf($k)) {
                $truempfe++;
                if ($k->wert === Kartenwert::DAME || $k->wert === Kartenwert::BUBE) {
                    $hoheTruempfe++;
                }
            }
        }

        if ($staerke === BotStaerke::ANFAENGER) {
            return $truempfe >= 5;
        }

        return $truempfe >= 5 || ($truempfe >= 4 && $hoheTruempfe >= 3);
    }

    /**
     * @param Karte[] $hand
     * @return string[] Genau $anzahl zurückzugebende Karten-IDs
     */
    public function kartenZurueckgeben(BotStaerke $staerke, array $hand, int $anzahl, TrumpfOrdnung $ordnung): array
    {
        $nichtTrumpf = array_values(array_filter($hand, fn(Karte $k) => !$ordnung->istTrumpf($k)));
        $truempfe    = array_values(array_filter($hand, fn(Karte $k) => $ordnung->istTrumpf($k)));

        $chosen = [];

        // Stärkere Bots: zuerst eine schwache Fehlfarbe komplett abgeben (frei werden).
        if ($staerke !== BotStaerke::ANFAENGER) {
            $farben = [];
            foreach ($nichtTrumpf as $k) {
                $farbe = $ordnung->fehlfarbe($k)?->value ?? '?';
                $farben[$farbe][] = $k;
            }

            // Farben nach (Augensumme, Kartenzahl) aufsteigend – schwächste zuerst.
            uasort($farben, function (array $a, array $b) {
                $augenA = array_sum(array_map(fn(Karte $k) => $k->augen(), $a));
                $augenB = array_sum(array_map(fn(Karte $k) => $k->augen(), $b));
                return $augenA <=> $augenB ?: count($a) <=> count($b);
            });

            foreach ($farben as $karten) {
                if (count($karten) <= $anzahl - count($chosen)) {
                    foreach ($karten as $k) {
                        $chosen[] = $k->id();
                    }
                }
            }
        }

        // Mit den augenärmsten übrigen Nicht-Trümpfen auffüllen.
        $rest = array_values(array_filter($nichtTrumpf, fn(Karte $k) => !in_array($k->id(), $chosen, true)));
        usort($rest, fn(Karte $a, Karte $b) => $a->augen() <=> $b->augen());
        foreach ($rest as $k) {
            if (count($chosen) >= $anzahl) {
                break;
            }
            $chosen[] = $k->id();
        }

        // Falls immer noch zu wenige: die niedrigsten Trümpfe abgeben.
        if (count($chosen) < $anzahl) {
            usort($truempfe, fn(Karte $a, Karte $b) => $ordnung->trumpfRang($a) <=> $ordnung->trumpfRang($b));
            foreach ($truempfe as $k) {
                if (count($chosen) >= $anzahl) {
                    break;
                }
                $chosen[] = $k->id();
            }
        }

        return array_slice($chosen, 0, $anzahl);
    }
}
