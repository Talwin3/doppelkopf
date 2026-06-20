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
 * (ein Spieler hält beide Trumpffarb-Asse). Liegt zusätzlich ein Superschweinchen vor
 * (ein Spieler hält beide Trumpffarb-Neuner), werden auch die Neuner hochgestuft.
 *
 * Die Trumpffarbe ist Karo (Normalspiel/Hochzeit/Karo-Solo) oder die jeweilige
 * Farb-Solo-Farbe (Pik/Herz/Kreuz-Solo).
 *
 * Rang-Hierarchie (hoch → niedrig):
 *   15  Superschweinchen (Trumpffarb-Neuner) — nur wenn $superschweinchen
 *   14  Schweinchen (Trumpffarb-Asse)
 *   13  Dulle (Herz-Zehn)
 *   12… alle anderen Trumpfränge der Basisordnung
 */
final class SchweinchentTrumpfOrdnung implements TrumpfOrdnung
{
    public function __construct(
        private readonly TrumpfOrdnung $inner,
        private readonly Kartenfarbe $trumpffarbe,
        private readonly bool $superschweinchen,
    ) {}

    public function istTrumpf(Karte $karte): bool
    {
        return $this->inner->istTrumpf($karte);
    }

    public function trumpfRang(Karte $karte): int
    {
        if ($karte->farbe === $this->trumpffarbe && $karte->wert === Kartenwert::ASS) {
            return 14; // Schweinchen
        }

        if ($this->superschweinchen
            && $karte->farbe === $this->trumpffarbe
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
