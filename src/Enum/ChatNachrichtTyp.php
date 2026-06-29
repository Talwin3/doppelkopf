<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Art einer Chat-Nachricht am Tisch. SYSTEM-Nachrichten werden vom
 * {@see \App\Application\Doppelkopf\TischProtokollService} erzeugt und
 * im Chat optisch klar abgesetzt dargestellt (kein Absender-Bubble).
 */
enum ChatNachrichtTyp: string
{
    /** Normale Spieler-Nachricht. */
    case SPIELER = 'SPIELER';

    /** Automatisch erzeugtes Tisch-Ereignis (Event-Log). */
    case SYSTEM = 'SYSTEM';
}
