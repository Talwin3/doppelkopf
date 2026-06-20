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

        $basis  = $this->fuer($variante);
        $status = $this->schweinchenStatus($spiel);

        if (!$status['schweinchen']) {
            return $basis;
        }

        return new SchweinchentTrumpfOrdnung($basis, $status['superschweinchen']);
    }

    /**
     * Ermittelt, ob in diesem Spiel tatsächlich ein Schweinchen bzw. Superschweinchen vorliegt.
     *
     * - Schweinchen: Regel aktiv (nur Normalspiel/Hochzeit) UND ein Spieler hält beide
     *   Trumpf-Asse (Karo-Asse) in der Starthand.
     * - Superschweinchen: zusätzlich Regel aktiv UND ein Spieler hält beide Trumpf-Neuner
     *   (Karo-Neuner) — nicht zwingend derselbe Spieler wie beim Schweinchen.
     *
     * @return array{schweinchen: bool, superschweinchen: bool}
     */
    public function schweinchenStatus(Spiel $spiel): array
    {
        $variante = $spiel->getVariante();

        // Schweinchen gilt nur bei Normalspiel und Hochzeit (nicht in Soli)
        if ($variante !== SpielVariante::NORMALSPIEL && $variante !== SpielVariante::HOCHZEIT) {
            return ['schweinchen' => false, 'superschweinchen' => false];
        }

        $regel = $spiel->getTisch()->getRegelEinstellungen();

        $schweinchen = !empty($regel['schweinchen'])
            && $this->einSpielerHaeltBeide($spiel, 'KARO_ASS_1', 'KARO_ASS_2');

        $superschweinchen = $schweinchen
            && !empty($regel['superschweinchen'])
            && $this->einSpielerHaeltBeide($spiel, 'KARO_NEUN_1', 'KARO_NEUN_2');

        return ['schweinchen' => $schweinchen, 'superschweinchen' => $superschweinchen];
    }

    /** Prüft, ob ein einzelner Spieler beide genannten Karten in der Starthand hält. */
    private function einSpielerHaeltBeide(Spiel $spiel, string $karteId1, string $karteId2): bool
    {
        foreach ($spiel->getTeilnehmer() as $teilnehmer) {
            $ids = $teilnehmer->getStartkartenIds();
            if (in_array($karteId1, $ids, true) && in_array($karteId2, $ids, true)) {
                return true;
            }
        }

        return false;
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
