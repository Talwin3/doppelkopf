<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Regel\Normalspiel;

use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Decorator: Erweitert eine TrumpfOrdnung um Schweinchen-/Superschweinchen-Regeln.
 *
 * Rang-Hierarchie (hoch → niedrig):
 *   15  Superschweinchen (Karo-Asse, wenn ein Spieler beide Exemplare in Starthand hält)
 *   14  Schweinchen (Karo-Asse, normal verteilt)
 *   13  Dulle (Herz-Zehn)
 *   12… alle anderen Trumpfränge der Basisordnung
 *
 * Gilt nur für NORMALSPIEL und HOCHZEIT — Solo-Varianten übergehen diesen Decorator.
 */
final class SchweinchentTrumpfOrdnung implements TrumpfOrdnung
{
    public function __construct(
        private readonly TrumpfOrdnung $inner,
        private readonly int $karoAssRang, // 14 = Schweinchen, 15 = Superschweinchen
    ) {}

    public function istTrumpf(Karte $karte): bool
    {
        return $this->inner->istTrumpf($karte);
    }

    public function trumpfRang(Karte $karte): int
    {
        if ($karte->farbe === Kartenfarbe::KARO && $karte->wert === Kartenwert::ASS) {
            return $this->karoAssRang;
        }

        return $this->inner->trumpfRang($karte);
    }

    public function fehlfarbenRang(Karte $karte): int
    {
        return $this->inner->fehlfarbenRang($karte);
    }

    public function fehlfarbe(Karte $karte): ?Kartenfarbe
    {
        return $this->inner->fehlfarbe($karte);
    }
}
