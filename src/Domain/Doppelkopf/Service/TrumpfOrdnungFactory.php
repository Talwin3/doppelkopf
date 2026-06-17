<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Normalspiel\SchweinchentTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\BubenSoloTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\DamenSoloTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\FarbSoloTrumpfOrdnung;
use App\Domain\Doppelkopf\Regel\Solo\FleischlosSoloTrumpfOrdnung;
use App\Entity\Spiel;
use App\Enum\Kartenfarbe;
use App\Enum\SpielVariante;

final class TrumpfOrdnungFactory
{
    public function fuerSpiel(Spiel $spiel): TrumpfOrdnung
    {
        $variante = $spiel->getVariante();
        if ($variante === null) {
            return new NormalspielTrumpfOrdnung(); // Fallback während Vorbehaltsrunde
        }

        $basis = $this->fuer($variante);

        // Schweinchen gilt nur bei Normalspiel und Hochzeit (nicht in Soli)
        if ($variante !== SpielVariante::NORMALSPIEL && $variante !== SpielVariante::HOCHZEIT) {
            return $basis;
        }

        $regel = $spiel->getTisch()->getRegelEinstellungen();
        if (empty($regel['schweinchen'])) {
            return $basis;
        }

        $karoAssRang = $this->ermittleKaroAssRang($spiel, $regel);

        return new SchweinchentTrumpfOrdnung($basis, $karoAssRang);
    }

    /** Superschweinchen (Rang 15) wenn ein Spieler beide Karo-Asse in der Starthand hält. */
    private function ermittleKaroAssRang(Spiel $spiel, array $regel): int
    {
        if (empty($regel['superschweinchen'])) {
            return 14;
        }

        foreach ($spiel->getTeilnehmer() as $teilnehmer) {
            $ids = $teilnehmer->getStartkartenIds();
            if (in_array('KARO_ASS_1', $ids, true) && in_array('KARO_ASS_2', $ids, true)) {
                return 15;
            }
        }

        return 14; // Asse auf zwei Spieler verteilt → normales Schweinchen
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
