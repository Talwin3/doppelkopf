<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Domain\Doppelkopf\ValueObject\Kartenstapel;

final class KartenGeber
{
    /**
     * Mischt und teilt Karten aus. Bei ohneNeuner=true wird der 40-Karten-Stapel genutzt.
     *
     * @param array<string, mixed> $regelEinstellungen Tisch-Regelwerk
     * @return array{0: Karte[], 1: Karte[], 2: Karte[], 3: Karte[]} Indizes 0–3 = Sitzplätze 1–4
     */
    public function austeilen(array $regelEinstellungen = []): array
    {
        $ohneNeuner = (bool) ($regelEinstellungen['ohne_neuner'] ?? false);
        $stapel     = $ohneNeuner ? Kartenstapel::ohneNeuner() : Kartenstapel::komplett();

        return $stapel->mischen()->austeilen();
    }
}
