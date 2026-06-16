<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Regel\Normalspiel;

use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * DDV-Normalspiel-Trumpfreihenfolge (hoch → niedrig):
 * 1. Herz-Zehn (Dulle) ×2
 * 2–5. Damen: Kreuz > Pik > Herz > Karo
 * 6–9. Buben: Kreuz > Pik > Herz > Karo
 * 10–13. Karo-Karten: Ass > Zehn > König > Neun
 */
final class NormalspielTrumpfOrdnung implements TrumpfOrdnung
{
    public function istTrumpf(Karte $karte): bool
    {
        if ($karte->farbe === Kartenfarbe::HERZ && $karte->wert === Kartenwert::ZEHN) {
            return true; // Dulle
        }

        return $karte->wert === Kartenwert::DAME
            || $karte->wert === Kartenwert::BUBE
            || $karte->farbe === Kartenfarbe::KARO;
    }

    public function trumpfRang(Karte $karte): int
    {
        // Dulle
        if ($karte->farbe === Kartenfarbe::HERZ && $karte->wert === Kartenwert::ZEHN) {
            return 13;
        }

        // Damen
        if ($karte->wert === Kartenwert::DAME) {
            return match ($karte->farbe) {
                Kartenfarbe::KREUZ => 12,
                Kartenfarbe::PIK   => 11,
                Kartenfarbe::HERZ  => 10,
                Kartenfarbe::KARO  => 9,
            };
        }

        // Buben
        if ($karte->wert === Kartenwert::BUBE) {
            return match ($karte->farbe) {
                Kartenfarbe::KREUZ => 8,
                Kartenfarbe::PIK   => 7,
                Kartenfarbe::HERZ  => 6,
                Kartenfarbe::KARO  => 5,
            };
        }

        // Karo-Karten (alle restlichen Karo sind Trumpf)
        return match ($karte->wert) {
            Kartenwert::ASS    => 4,
            Kartenwert::ZEHN   => 3,
            Kartenwert::KOENIG => 2,
            Kartenwert::NEUN   => 1,
            default            => throw new \LogicException('Ungültige Trumpfkarte: ' . $karte->id()),
        };
    }

    public function fehlfarbenRang(Karte $karte): int
    {
        return match ($karte->wert) {
            Kartenwert::ASS    => 4,
            Kartenwert::ZEHN   => 3,
            Kartenwert::KOENIG => 2,
            Kartenwert::NEUN   => 1,
            default            => throw new \LogicException('Dame/Bube sind immer Trumpf, kein Fehlfarbenrang.'),
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
