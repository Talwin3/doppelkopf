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

    /**
     * 40-Karten-Stapel ohne alle 8 Neuner ("Ohne Neuner"-Variante).
     * Alle anderen Regeln bleiben identisch; 10 Karten je Spieler.
     */
    public static function ohneNeuner(): self
    {
        $karten = [];
        foreach (Kartenfarbe::cases() as $farbe) {
            foreach (Kartenwert::cases() as $wert) {
                if ($wert === Kartenwert::NEUN) {
                    continue; // Neuner entfernen
                }
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
     * Verteilt alle Karten gleichmäßig auf 4 Spieler.
     * Funktioniert mit 48-Karten-Stapel (12 je Spieler) und 40-Karten-Stapel (10 je Spieler).
     *
     * @return array{0: Karte[], 1: Karte[], 2: Karte[], 3: Karte[]}
     */
    public function austeilen(): array
    {
        $anzahl = count($this->karten);
        if ($anzahl !== 48 && $anzahl !== 40) {
            throw new \LogicException('Stapel muss genau 48 oder 40 Karten enthalten (hat: ' . $anzahl . ').');
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
