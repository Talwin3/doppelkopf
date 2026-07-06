<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\SpielVariante;
use App\Enum\VorbehaltTyp;

/**
 * Ergebnis der Bot-Entscheidung in der Vorbehaltsrunde: welcher Vorbehaltstyp
 * (bzw. gesund) und – bei Solo – welche Variante.
 */
final class VorbehaltEntscheidung
{
    public function __construct(
        public readonly VorbehaltTyp $typ,
        public readonly ?SpielVariante $soloVariante = null,
    ) {}

    public static function gesund(): self
    {
        return new self(VorbehaltTyp::GESUND);
    }

    public static function solo(SpielVariante $variante): self
    {
        return new self(VorbehaltTyp::SOLO, $variante);
    }
}
