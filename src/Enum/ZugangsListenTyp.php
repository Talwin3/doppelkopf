<?php

declare(strict_types=1);

namespace App\Enum;

enum ZugangsListenTyp: string
{
    case WHITELIST = 'WHITELIST';
    case BLACKLIST = 'BLACKLIST';
}
