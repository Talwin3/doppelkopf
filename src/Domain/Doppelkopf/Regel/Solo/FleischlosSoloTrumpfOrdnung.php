<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Regel\Solo;

use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Solo Fleischlos: Keine Trumpfkarten.
 * Damen und Buben sind normale Karten (kein Sonderstatus).
 * Stichhierarchie pro Farbe: Ass > Zehn > König > Dame > Bube > Neun.
 * Stich geht an den, der die höchste Karte der angespielten Farbe hat.
 * ≠ Null-Spiel: Solist muss ≥121 Augen sammeln (nicht 0 Stiche).
 *
 * Bei ohne_neuner=true: Hierarchie Ass > Zehn > König > Dame > Bube (Neun entfällt aus Stapel).
 */
final class FleischlosSoloTrumpfOrdnung implements TrumpfOrdnung
{
    public function istTrumpf(Karte $karte): bool
    {
        return false; // Kein Trumpf im Fleischlos-Solo
    }

    public function trumpfRang(Karte $karte): int
    {
        throw new \LogicException('Fleischlos-Solo hat keinen Trumpf.');
    }

    public function fehlfarbenRang(Karte $karte): int
    {
        return match ($karte->wert) {
            Kartenwert::ASS    => 6,
            Kartenwert::ZEHN   => 5,
            Kartenwert::KOENIG => 4,
            Kartenwert::DAME   => 3,
            Kartenwert::BUBE   => 2,
            Kartenwert::NEUN   => 1,
        };
    }

    public function fehlfarbe(Karte $karte): ?Kartenfarbe
    {
        return $karte->farbe; // Alle Karten haben ihre eigene Farbe als Fehlfarbe
    }
}
