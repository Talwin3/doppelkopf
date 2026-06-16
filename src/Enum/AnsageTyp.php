<?php

declare(strict_types=1);

namespace App\Enum;

enum AnsageTyp: string
{
    case RE         = 'RE';
    case CONTRA     = 'CONTRA';
    case KEINE_NEUN = 'KEINE_NEUN';
    case KEINE_SECHS = 'KEINE_SECHS';
    case KEINE_DREI = 'KEINE_DREI';
    case SCHWARZ    = 'SCHWARZ';
    case HOCHZEIT   = 'HOCHZEIT';
    case SOLO       = 'SOLO';
}
