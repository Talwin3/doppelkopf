<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Auswählbares Kartendesign (französisches Blatt).
 *
 * - KLASSISCH: prozedural in {@see \App\Twig\Extension\KartenSvgExtension} gezeichnet (schlicht)
 * - BELLOT:    SVG-cards von David Bellot (LGPL-2.1) — public/cards/bellot/
 * - KNOLL:     Vector Playing Cards von Byron Knoll (Public Domain) — public/cards/knoll/
 */
enum Kartendeck: string
{
    case KLASSISCH = 'KLASSISCH';
    case BELLOT    = 'BELLOT';
    case KNOLL     = 'KNOLL';

    /** Systemweiter Default für neue Nutzer. Hier zentral änderbar. */
    public static function default(): self
    {
        return self::BELLOT;
    }

    /** Toleranter Lookup: unbekannte/Alt-Werte fallen auf den Default zurück. */
    public static function vonWert(?string $wert): self
    {
        return ($wert !== null ? self::tryFrom($wert) : null) ?? self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::KLASSISCH => 'Klassisch (schlicht)',
            self::BELLOT    => 'Französisch — Bellot',
            self::KNOLL     => 'Französisch — Byron Knoll',
        };
    }
}
