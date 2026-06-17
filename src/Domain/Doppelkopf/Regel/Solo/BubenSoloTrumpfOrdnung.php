<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Regel\Solo;

use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Solo Buben: Nur die 4 Buben (×2) sind Trumpf.
 * Hierarchie: Kreuz > Pik > Herz > Karo.
 * Keine Damen-Sonderstellung, kein Dulle, kein Karo-Trumpf.
 */
final class BubenSoloTrumpfOrdnung implements TrumpfOrdnung
{
    public function istTrumpf(Karte $karte): bool
    {
        return $karte->wert === Kartenwert::BUBE;
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
            Kartenwert::ASS    => 6,
            Kartenwert::ZEHN   => 5,
            Kartenwert::KOENIG => 4,
            Kartenwert::DAME   => 3,
            Kartenwert::NEUN   => 1,
            default            => throw new \LogicException('Buben sind Trumpf, kein Fehlfarbenrang für: ' . $karte->id()),
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
