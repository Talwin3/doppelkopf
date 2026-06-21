<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Exception;

final class TischVerlassenGesperrtException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Du kannst den Tisch nicht verlassen, solange du an einem laufenden Spiel '
            . 'teilnimmst. Nutze „Nach Spiel verlassen“, um nach dem Spiel zu gehen.'
        );
    }
}
