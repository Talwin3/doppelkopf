<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\BubenSoloTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\DamenSoloTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\FarbSoloTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\FleischlosSoloTrumpfOrdnung;
use App\Entity\Spiel;
use App\Enum\Kartenfarbe;
use App\Enum\SpielVariante;

/**
 * Erzeugt die passende TrumpfOrdnung für ein laufendes Spiel.
 *
 * Schweinchen-Support (Phase 2, Regelwerk-Feld): Da Schweinchen die TrumpfOrdnung
 * von den Startkarten abhängig macht (stateful), wird hier die Spiel-Instanz
 * übergeben, damit die Factory Zugriff auf Teilnehmer-Karten hat.
 *
 * Aktuell: Schweinchen-Logik wird bei schweinchen=false übersprungen.
 */
final class TrumpfOrdnungFactory
{
    public function fuerSpiel(Spiel $spiel): TrumpfOrdnung
    {
        return $this->fuer($spiel->getVariante());
    }

    public function fuer(SpielVariante $variante): TrumpfOrdnung
    {
        return match ($variante) {
            SpielVariante::NORMALSPIEL, SpielVariante::HOCHZEIT => new NormalspielTrumpfOrdnung(),
            SpielVariante::SOLO_BUBEN     => new BubenSoloTrumpfOrdnung(),
            SpielVariante::SOLO_DAMEN     => new DamenSoloTrumpfOrdnung(),
            SpielVariante::SOLO_FLEISCHLOS => new FleischlosSoloTrumpfOrdnung(),
            SpielVariante::SOLO_KARO      => new FarbSoloTrumpfOrdnung(Kartenfarbe::KARO),
            SpielVariante::SOLO_HERZ      => new FarbSoloTrumpfOrdnung(Kartenfarbe::HERZ),
            SpielVariante::SOLO_PIK       => new FarbSoloTrumpfOrdnung(Kartenfarbe::PIK),
            SpielVariante::SOLO_KREUZ     => new FarbSoloTrumpfOrdnung(Kartenfarbe::KREUZ),
        };
    }
}
