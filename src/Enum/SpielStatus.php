<?php

declare(strict_types=1);

namespace App\Enum;

enum SpielStatus: string
{
    /** Karten wurden ausgeteilt, Spieler deklarieren ihre Vorbehalte. */
    case VORBEHALT = 'VORBEHALT';
    case LAUFEND   = 'LAUFEND';
    case BEENDET   = 'BEENDET';
}
