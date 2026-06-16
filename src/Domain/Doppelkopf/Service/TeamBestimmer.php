<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielVariante;
use App\Enum\Team;

final class TeamBestimmer
{
    /**
     * Bestimmt Teams anhand der Kreuz-Dame-Regel.
     *
     * @param array<int, Karte[]> $haende Sitzplatz (1–4) → Kartenarray
     * @return array{teams: array<int, Team>, variante: SpielVariante, hochzeitSitzplatz: int|null}
     */
    public function bestimme(array $haende): array
    {
        $kreuzDamenAnzahl = [];
        foreach ($haende as $sitzplatz => $karten) {
            $kreuzDamenAnzahl[$sitzplatz] = 0;
            foreach ($karten as $karte) {
                if ($karte->farbe === Kartenfarbe::KREUZ && $karte->wert === Kartenwert::DAME) {
                    $kreuzDamenAnzahl[$sitzplatz]++;
                }
            }
        }

        $hochzeitSitzplatz = null;
        foreach ($kreuzDamenAnzahl as $sitzplatz => $anzahl) {
            if ($anzahl === 2) {
                $hochzeitSitzplatz = $sitzplatz;
            }
        }

        $teams = [];
        foreach ($kreuzDamenAnzahl as $sitzplatz => $anzahl) {
            $teams[$sitzplatz] = $anzahl > 0 ? Team::RE : Team::KONTRA;
        }

        $variante = $hochzeitSitzplatz !== null ? SpielVariante::HOCHZEIT : SpielVariante::NORMALSPIEL;

        return [
            'teams'             => $teams,
            'variante'          => $variante,
            'hochzeitSitzplatz' => $hochzeitSitzplatz,
        ];
    }
}
