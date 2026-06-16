<?php

declare(strict_types=1);

namespace App\Enum;

enum SpielStatus: string
{
    case LAUFEND = 'LAUFEND';
    case BEENDET = 'BEENDET';
}
