<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Domain\Doppelkopf\ValueObject\Kartenstapel;

final class KartenGeber
{
    /**
     * Mischt und teilt 48 Karten an 4 Spieler aus.
     * @return array{0: Karte[], 1: Karte[], 2: Karte[], 3: Karte[]} Indizes 0–3 = Sitzplätze 1–4
     */
    public function austeilen(): array
    {
        return Kartenstapel::komplett()->mischen()->austeilen();
    }
}
