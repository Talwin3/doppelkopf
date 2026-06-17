<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Regel\Solo;

use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Solo Damen: Nur die 4 Damen (×2) sind Trumpf.
 * Hierarchie: Kreuz > Pik > Herz > Karo.
 * Keine Buben-Sonderstellung, kein Dulle, kein Karo-Trumpf.
 */
final class DamenSoloTrumpfOrdnung implements TrumpfOrdnung
{
    public function istTrumpf(Karte $karte): bool
    {
        return $karte->wert === Kartenwert::DAME;
    }

    public function trumpfRang(Karte $karte): int
    {
        return match ($karte->farbe) {
            Kartenfarbe::KREUZ => 4,
            Kartenfarbe::PIK   => 3,
            Kartenfarbe::HERZ  => 2,
            Kartenfarbe::KARO  => 1,
        };
    }

    public function fehlfarbenRang(Karte $karte): int
    {
        return match ($karte->wert) {
            Kartenwert::ASS    => 5,
            Kartenwert::ZEHN   => 4,
            Kartenwert::KOENIG => 3,
            Kartenwert::BUBE   => 2,
            Kartenwert::NEUN   => 1,
            default            => throw new \LogicException('Damen sind Trumpf, kein Fehlfarbenrang für: ' . $karte->id()),
        };
    }

    public function fehlfarbe(Karte $karte): ?Kartenfarbe
    {
        if ($this->istTrumpf($karte)) {
            return null;
        }

        return $karte->farbe;
    }
}
