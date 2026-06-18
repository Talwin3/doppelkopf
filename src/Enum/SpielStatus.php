<?php

declare(strict_types=1);

namespace App\Enum;

enum SpielStatus: string
{
    /** Karten wurden ausgeteilt, Spieler deklarieren ihre Vorbehalte. */
    case VORBEHALT     = 'VORBEHALT';
    /** Armut angemeldet — Spieler werden reihum gefragt ob sie annehmen. */
    case ARMUT_ANFRAGE = 'ARMUT_ANFRAGE';
    /** Annehmer tauscht Karten mit dem Armut-Spieler. */
    case ARMUT_TAUSCH  = 'ARMUT_TAUSCH';
    case LAUFEND       = 'LAUFEND';
    case BEENDET       = 'BEENDET';
}
