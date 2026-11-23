<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Welche Punkte am Tisch neben den Spielern stehen. Reine Anzeige: Umschalten
 * verändert keine Wertung.
 */
enum TischPunkteAnzeige: string
{
    /** Monatspunkte der Spieler in der Kategorie des Tisches, mit Listenplatz. Bots ohne Punkte. */
    case MONAT = 'MONAT';

    /** Summe aller Spiele an diesem Tisch seit seiner Erstellung, auch für Bots. */
    case TISCH = 'TISCH';

    public function label(): string
    {
        return match ($this) {
            self::MONAT => 'Monatspunkte',
            self::TISCH => 'Tischliste',
        };
    }
}
