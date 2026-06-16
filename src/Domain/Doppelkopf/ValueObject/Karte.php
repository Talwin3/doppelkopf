<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

/**
 * Immutable. `kopie` (1|2) unterscheidet die zwei physischen Exemplare.
 * Format der ID: "KREUZ_DAME_1", "KARO_NEUN_2", etc.
 */
final class Karte
{
    public function __construct(
        public readonly Kartenfarbe $farbe,
        public readonly Kartenwert  $wert,
        public readonly int         $kopie, // 1 oder 2
    ) {}

    public function id(): string
    {
        return $this->farbe->value . '_' . $this->wert->value . '_' . $this->kopie;
    }

    public static function vonId(string $id): self
    {
        $teile = explode('_', $id);
        // Format: FARBE_WERT_KOPIE – aber KOENIG hat keinen Unterstrich, KREUZ auch nicht
        // Letztes Element = Kopie, vorletztes = Wert, Rest = Farbe
        $kopie = (int) array_pop($teile);
        $wert  = Kartenwert::from(array_pop($teile));
        $farbe = Kartenfarbe::from(implode('_', $teile));

        return new self($farbe, $wert, $kopie);
    }

    public function augen(): int
    {
        return $this->wert->augen();
    }

    /** Zwei Karten sind "gleich" im Spielsinne wenn Farbe und Wert identisch sind (egal welche Kopie). */
    public function gleichwertigMit(self $andere): bool
    {
        return $this->farbe === $andere->farbe && $this->wert === $andere->wert;
    }
}
