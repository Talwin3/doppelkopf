<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Auswählbarer Avatar-Stil (DiceBear, self-hosted via {@see \App\Application\AvatarService}).
 *
 * Es werden nur lizenzkonforme Stile angeboten:
 *  - CC0 1.0 (Public Domain, keine Namensnennung nötig): LORELEI, NOTIONISTS,
 *    OPEN_PEEPS, PIXEL_ART, THUMBS, SHAPES
 *  - „Free for personal and commercial use" (Namensnennung empfohlen, siehe Impressum):
 *    AVATAAARS (Pablo Stanley), BOTTTS (Pablo Stanley)
 *
 * Der `value` entspricht exakt dem Dateinamen des Stils im Paket dicebear/styles
 * (vendor/dicebear/styles/src/<value>.json).
 */
enum AvatarStil: string
{
    case LORELEI    = 'lorelei';
    case NOTIONISTS = 'notionists';
    case OPEN_PEEPS = 'open-peeps';
    case PIXEL_ART  = 'pixel-art';
    case THUMBS     = 'thumbs';
    case SHAPES     = 'shapes';
    case AVATAAARS  = 'avataaars';
    case BOTTTS     = 'bottts';

    /** Systemweiter Default für neue Nutzer. Hier zentral änderbar. */
    public static function default(): self
    {
        return self::LORELEI;
    }

    /** Default-Stil für Bot-Platzhalter. */
    public static function defaultBot(): self
    {
        return self::BOTTTS;
    }

    /** Toleranter Lookup: unbekannte/Alt-Werte fallen auf den Default zurück. */
    public static function vonWert(?string $wert): self
    {
        return ($wert !== null ? self::tryFrom($wert) : null) ?? self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::LORELEI    => 'Illustriert (Lorelei)',
            self::NOTIONISTS => 'Notion-Stil',
            self::OPEN_PEEPS => 'Handgezeichnet (Open Peeps)',
            self::PIXEL_ART  => 'Pixel-Art',
            self::THUMBS     => 'Abstrakt (Thumbs)',
            self::SHAPES     => 'Geometrisch',
            self::AVATAAARS  => 'Comic (Avataaars)',
            self::BOTTTS     => 'Roboter (Bottts)',
        };
    }

    /** Kurzbezeichnung der Lizenz – für Anzeige im Profil. */
    public function lizenz(): string
    {
        return match ($this) {
            self::AVATAAARS, self::BOTTTS => 'Frei für privat & kommerziell',
            default                       => 'CC0 1.0 (Public Domain)',
        };
    }

    /**
     * Attributionspflicht? Die CC0-Stile benötigen keine Namensnennung,
     * Avataaars/Bottts empfehlen sie (im Impressum hinterlegt).
     */
    public function brauchtNamensnennung(): bool
    {
        return $this === self::AVATAAARS || $this === self::BOTTTS;
    }
}
