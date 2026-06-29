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

    public function label(): string
    {
        return match ($this) {
            self::RE         => 'Re',
            self::CONTRA     => 'Contra',
            self::KEINE_NEUN => 'keine 90',
            self::KEINE_SECHS => 'keine 60',
            self::KEINE_DREI => 'keine 30',
            self::SCHWARZ    => 'schwarz',
            self::HOCHZEIT   => 'Hochzeit',
            self::SOLO       => 'Solo',
        };
    }
}
