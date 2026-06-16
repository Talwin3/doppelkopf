<?php

declare(strict_types=1);

namespace App\Enum;

enum Kartenfarbe: string
{
    case KREUZ = 'KREUZ';
    case PIK   = 'PIK';
    case HERZ  = 'HERZ';
    case KARO  = 'KARO';

    public function symbol(): string
    {
        return match ($this) {
            self::KREUZ => '♣',
            self::PIK   => '♠',
            self::HERZ  => '♥',
            self::KARO  => '♦',
        };
    }

    public function istRot(): bool
    {
        return $this === self::HERZ || $this === self::KARO;
    }
}
