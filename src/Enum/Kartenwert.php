<?php

declare(strict_types=1);

namespace App\Enum;

enum Kartenwert: string
{
    case ASS    = 'ASS';
    case ZEHN   = 'ZEHN';
    case KOENIG = 'KOENIG';
    case DAME   = 'DAME';
    case BUBE   = 'BUBE';
    case NEUN   = 'NEUN';

    public function anzeige(): string
    {
        return match ($this) {
            self::ASS    => 'A',
            self::ZEHN   => '10',
            self::KOENIG => 'K',
            self::DAME   => 'D',
            self::BUBE   => 'B',
            self::NEUN   => '9',
        };
    }

    /** Augenwert nach DDV-Regeln. */
    public function augen(): int
    {
        return match ($this) {
            self::ASS    => 11,
            self::ZEHN   => 10,
            self::KOENIG => 4,
            self::DAME   => 3,
            self::BUBE   => 2,
            self::NEUN   => 0,
        };
    }
}
