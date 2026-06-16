<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Exception;

final class TischZugangVerweigertException extends \RuntimeException
{
    public function __construct(string $grund)
    {
        parent::__construct($grund);
    }

    public static function weilBlacklist(): self
    {
        return new self('Ein Spieler am Tisch hat dich blockiert.');
    }

    public static function weilNichtAufWhitelist(): self
    {
        return new self('Dieser Tisch ist privat – du stehst nicht auf der Gästeliste des Erstellers.');
    }

    public static function weilBereitsAmTisch(): self
    {
        return new self('Du bist bereits an diesem Tisch.');
    }
}
