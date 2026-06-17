<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Regel\Solo;

use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Farb-Solo (Karo, Herz, Pik, Kreuz): Die gewählte Farbe ist Trumpf.
 *
 * Trumpfreihenfolge (hoch → niedrig):
 * 1. Herz-Zehn (Dulle) ×2 — bleibt auch in Farb-Soli die höchste Trumpfkarte
 * 2–5. Damen: Kreuz > Pik > Herz > Karo
 * 6–9. Buben: Kreuz > Pik > Herz > Karo
 * 10+. Solo-Farbe (ohne Dulle falls Herz-Solo): Ass > Zehn > König > Neun
 *
 * In Herz-Solo: Herz-Zehn ist Dulle (Rang 13), nicht reguläre Herz-Karte.
 * Verbleibende Herz-Karten: Ass(4), König(2), Neun(1). (Herz-Zehn = Dulle, Herz-Dame/Bube = trump durch Dame/Bube-Regel)
 */
final class FarbSoloTrumpfOrdnung implements TrumpfOrdnung
{
    public function __construct(private readonly Kartenfarbe $soloFarbe) {}

    public function istTrumpf(Karte $karte): bool
    {
        // Dulle ist immer Trumpf (Herz-Zehn)
        if ($karte->farbe === Kartenfarbe::HERZ && $karte->wert === Kartenwert::ZEHN) {
            return true;
        }

        // Damen und Buben immer Trumpf
        if ($karte->wert === Kartenwert::DAME || $karte->wert === Kartenwert::BUBE) {
            return true;
        }

        // Solo-Farbe ist Trumpf
        return $karte->farbe === $this->soloFarbe;
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

        // Solo-Farbkarten (kein Dame/Bube, keine Dulle)
        return match ($karte->wert) {
            Kartenwert::ASS    => 4,
            Kartenwert::ZEHN   => 3, // Herz-Zehn wurde oben als Dulle abgefangen
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
            default            => throw new \LogicException('Dame/Bube sind Trumpf, kein Fehlfarbenrang.'),
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
