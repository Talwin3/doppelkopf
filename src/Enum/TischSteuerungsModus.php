<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Legt fest, wer die Tisch-Einstellungen (Regelwerk, Auto-Start, Bots auffüllen)
 * verändern darf. Wird vom {@see \App\Security\Voter\TischAktionVoter} ausgewertet.
 */
enum TischSteuerungsModus: string
{
    /** Nur der Tischersteller darf Einstellungen ändern. */
    case NUR_ERSTELLER = 'NUR_ERSTELLER';

    /** Jeder aktive Spieler am Tisch darf Einstellungen ändern. */
    case ALLE = 'ALLE';

    /**
     * Default für neu erstellte Tische (im Formular vorausgewählt).
     * Bestehende Tische behalten über die Migrations-Spalten-Default das
     * bisherige Verhalten ({@see self::ALLE}).
     */
    public static function default(): self
    {
        return self::NUR_ERSTELLER;
    }

    public function label(): string
    {
        return match ($this) {
            self::NUR_ERSTELLER => 'Nur ich (Tischersteller)',
            self::ALLE          => 'Alle Spieler am Tisch',
        };
    }
}
