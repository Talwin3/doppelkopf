<?php

declare(strict_types=1);

namespace App\Enum;

enum VorbehaltTyp: string
{
    case SOLO     = 'SOLO';
    case ARMUT    = 'ARMUT';
    case HOCHZEIT = 'HOCHZEIT';
    case GESUND   = 'GESUND';
}
