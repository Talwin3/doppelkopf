<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Spielstärke eines Bot-Platzhalters. Bestimmt, welche {@see \App\Application\Doppelkopf\Bot\BotStrategie}
 * seine Züge berechnet.
 *
 * Ausbaustufen (siehe project-bot-staerke-plan):
 *  - ANFAENGER      — regelbasierte Heuristik (aktueller Bestand), immer verfügbar.
 *  - FORTGESCHRITTEN — verbesserte Heuristik mit Kartengedächtnis/Partner-Inferenz (in Arbeit).
 *  - PROFI          — Monte-Carlo-Suche (PIMC) über den gesamten Spielverlauf (geplant).
 *
 * Noch nicht implementierte Stufen fallen im {@see \App\Application\Doppelkopf\Bot\BotStrategieProvider}
 * auf die nächstbeste verfügbare Strategie zurück, damit nie ein Bot ohne Zuglogik dasteht.
 */
enum BotStaerke: string
{
    case ANFAENGER       = 'anfaenger';
    case FORTGESCHRITTEN = 'fortgeschritten';
    case PROFI           = 'profi';

    /** Systemweiter Default für neue Bots. Hier zentral änderbar. */
    public static function default(): self
    {
        return self::ANFAENGER;
    }

    /** Toleranter Lookup: unbekannte/Alt-Werte fallen auf den Default zurück. */
    public static function vonWert(?string $wert): self
    {
        return ($wert !== null ? self::tryFrom($wert) : null) ?? self::default();
    }

    /**
     * Ist für diese Stufe bereits eine eigene Strategie implementiert? Noch nicht
     * fertige Stufen werden weder am Tisch noch in der Admin angeboten (und fallen
     * im Provider auf {@see self::default()} zurück).
     */
    public function implementiert(): bool
    {
        return match ($this) {
            self::ANFAENGER, self::FORTGESCHRITTEN => true,
            default                                => false, // PROFI: in Arbeit (Phase 4)
        };
    }

    /**
     * Alle tatsächlich anwählbaren Stufen (implementiert). Steuert die Auswahl
     * am Tisch und in der Administration.
     *
     * @return self[]
     */
    public static function verfuegbare(): array
    {
        return array_values(array_filter(self::cases(), fn(self $s) => $s->implementiert()));
    }

    public function label(): string
    {
        return match ($this) {
            self::ANFAENGER       => 'Anfänger',
            self::FORTGESCHRITTEN => 'Fortgeschritten',
            self::PROFI           => 'Profi',
        };
    }

    public function beschreibung(): string
    {
        return match ($this) {
            self::ANFAENGER       => 'Solide Grundregeln – spielt vernünftig, aber ohne Gedächtnis.',
            self::FORTGESCHRITTEN => 'Merkt sich gespielte Karten und erkennt Partner/Gegner.',
            self::PROFI           => 'Rechnet mögliche Spielverläufe durch und spielt vorausschauend.',
        };
    }
}
