<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;

/**
 * Reine Bedienpflicht-Regel: welche Karten dürfen bei gegebenem Anspiel gespielt werden?
 * Spiegelt {@see \App\Application\Doppelkopf\KarteAusspielenService::erlaubteKarten},
 * aber datenbankfrei – für die Rollouts der {@see ProfiHeuristik}.
 */
final class LegaleKarten
{
    /**
     * @param Karte[] $hand
     * @param ?Karte  $angespielt Erste Karte des Stichs (null = Spieler spielt an)
     * @return Karte[] Spielbare Karten (mind. eine, sofern Hand nicht leer)
     */
    public static function fuer(array $hand, ?Karte $angespielt, TrumpfOrdnung $ordnung): array
    {
        $hand = array_values($hand);
        if ($angespielt === null || $hand === []) {
            return $hand;
        }

        if ($ordnung->istTrumpf($angespielt)) {
            $trumpf = array_values(array_filter($hand, fn(Karte $k) => $ordnung->istTrumpf($k)));

            return $trumpf !== [] ? $trumpf : $hand;
        }

        $farbe   = $ordnung->fehlfarbe($angespielt);
        $anfarbe = array_values(array_filter(
            $hand,
            fn(Karte $k) => !$ordnung->istTrumpf($k) && $ordnung->fehlfarbe($k) === $farbe,
        ));

        return $anfarbe !== [] ? $anfarbe : $hand;
    }
}
