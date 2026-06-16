<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;

final class Kartenstapel
{
    /** @param Karte[] $karten */
    private function __construct(private array $karten) {}

    /** Erstellt den vollständigen 48-Karten-Doppelkopfstapel. */
    public static function komplett(): self
    {
        $karten = [];
        foreach (Kartenfarbe::cases() as $farbe) {
            foreach (Kartenwert::cases() as $wert) {
                $karten[] = new Karte($farbe, $wert, 1);
                $karten[] = new Karte($farbe, $wert, 2);
            }
        }

        return new self($karten);
    }

    public function mischen(): self
    {
        $gemischt = $this->karten;
        shuffle($gemischt);

        return new self($gemischt);
    }

    /**
     * Gibt 4 Hände mit je 12 Karten zurück.
     * @return array{0: Karte[], 1: Karte[], 2: Karte[], 3: Karte[]}
     */
    public function austeilen(): array
    {
        if (count($this->karten) !== 48) {
            throw new \LogicException('Stapel muss genau 48 Karten enthalten.');
        }

        $haende = [[], [], [], []];
        foreach ($this->karten as $i => $karte) {
            $haende[$i % 4][] = $karte;
        }

        return $haende;
    }

    /** @return Karte[] */
    public function alleKarten(): array
    {
        return $this->karten;
    }
}
