<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;

interface TrumpfOrdnung
{
    public function istTrumpf(Karte $karte): bool;

    /**
     * Rang unter Trumpfkarten. Höherer Wert schlägt niedrigeren.
     * Nur für Karten aufrufen, bei denen istTrumpf() = true.
     */
    public function trumpfRang(Karte $karte): int;

    /**
     * Rang innerhalb einer Fehlfarbe (Ass > Zehn > König > Neun).
     * Nur für Nicht-Trumpf-Karten aufrufen.
     */
    public function fehlfarbenRang(Karte $karte): int;

    /**
     * Die Fehlfarbe einer Karte (null wenn Trumpf).
     * Wichtig: Herz-Zehn ist Trumpf, hat also keine Fehlfarbe Herz.
     */
    public function fehlfarbe(Karte $karte): ?Kartenfarbe;
}
