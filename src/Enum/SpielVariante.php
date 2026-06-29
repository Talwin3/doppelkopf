<?php

declare(strict_types=1);

namespace App\Enum;

enum SpielVariante: string
{
    case NORMALSPIEL    = 'NORMALSPIEL';
    case HOCHZEIT       = 'HOCHZEIT';
    case SOLO_BUBEN     = 'SOLO_BUBEN';
    case SOLO_DAMEN     = 'SOLO_DAMEN';
    case SOLO_FLEISCHLOS = 'SOLO_FLEISCHLOS';
    case SOLO_KARO      = 'SOLO_KARO';
    case SOLO_HERZ      = 'SOLO_HERZ';
    case SOLO_PIK       = 'SOLO_PIK';
    case SOLO_KREUZ     = 'SOLO_KREUZ';

    public function label(): string
    {
        return match ($this) {
            self::NORMALSPIEL    => 'Normalspiel',
            self::HOCHZEIT       => 'Hochzeit',
            self::SOLO_BUBEN     => 'Buben-Solo',
            self::SOLO_DAMEN     => 'Damen-Solo',
            self::SOLO_FLEISCHLOS => 'Fleischlos-Solo',
            self::SOLO_KARO      => 'Karo-Solo',
            self::SOLO_HERZ      => 'Herz-Solo',
            self::SOLO_PIK       => 'Pik-Solo',
            self::SOLO_KREUZ     => 'Kreuz-Solo',
        };
    }
}
