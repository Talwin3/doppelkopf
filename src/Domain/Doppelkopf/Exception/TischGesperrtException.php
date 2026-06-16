<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Exception;

final class TischGesperrtException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dieser Tisch ist gesperrt und nimmt keine neuen Spieler an.');
    }
}
