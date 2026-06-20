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
 * Dieser Decorator wird nur konstruiert, wenn das Schweinchen tatsächlich vorliegt
 * (ein Spieler hält beide Trumpf-Asse). Liegt zusätzlich ein Superschweinchen vor
 * (ein Spieler hält beide Trumpf-Neuner), werden auch die Neuner hochgestuft.
 *
 * Rang-Hierarchie (hoch → niedrig):
 *   15  Superschweinchen (Karo-Neuner) — nur wenn $superschweinchen
 *   14  Schweinchen (Karo-Asse)
 *   13  Dulle (Herz-Zehn)
 *   12… alle anderen Trumpfränge der Basisordnung
 *
 * Gilt nur für NORMALSPIEL und HOCHZEIT — Solo-Varianten übergehen diesen Decorator.
 */
final class SchweinchentTrumpfOrdnung implements TrumpfOrdnung
{
    public function __construct(
        private readonly TrumpfOrdnung $inner,
        private readonly bool $superschweinchen,
    ) {}

    public function istTrumpf(Karte $karte): bool
    {
        return $this->inner->istTrumpf($karte);
    }

    public function trumpfRang(Karte $karte): int
    {
        if ($karte->farbe === Kartenfarbe::KARO && $karte->wert === Kartenwert::ASS) {
            return 14; // Schweinchen
        }

        if ($this->superschweinchen
            && $karte->farbe === Kartenfarbe::KARO
            && $karte->wert === Kartenwert::NEUN) {
            return 15; // Superschweinchen — höchster Trumpf, über dem Schweinchen
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
