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
        // Während der Vorbehaltsrunde steht die Variante noch nicht fest (null).
        // Für die Sortierung gehen wir dann vom Normalspiel aus: Karo ist Trumpf,
        // und ein Schweinchen ist allein aus der Starthand bestimmbar — so erscheinen
        // die Karo-Asse bereits beim Gesund/Vorbehalt-Blatt korrekt als höchster Trumpf.
        $variante = $spiel->getVariante() ?? SpielVariante::NORMALSPIEL;

        $basis  = $this->fuer($variante);
        $status = $this->schweinchenStatus($spiel);

        if (!$status['schweinchen']) {
            return $basis;
        }

        // Trumpffarbe ist hier garantiert gesetzt (sonst wäre schweinchen=false).
        $trumpffarbe = $this->trumpffarbe($variante);

        return new SchweinchentTrumpfOrdnung($basis, $trumpffarbe, $status['superschweinchen']);
    }

    /**
     * Trumpffarbe, in der Schweinchen/Superschweinchen entstehen können:
     * Karo im Normalspiel/Hochzeit/Karo-Solo, sonst die jeweilige Farb-Solo-Farbe.
     * Buben-/Damen-/Fleischlos-Solo haben keine Trumpffarbe (→ null, kein Schweinchen).
     */
    private function trumpffarbe(SpielVariante $variante): ?Kartenfarbe
    {
        return match ($variante) {
            SpielVariante::NORMALSPIEL, SpielVariante::HOCHZEIT, SpielVariante::SOLO_KARO => Kartenfarbe::KARO,
            SpielVariante::SOLO_HERZ  => Kartenfarbe::HERZ,
            SpielVariante::SOLO_PIK   => Kartenfarbe::PIK,
            SpielVariante::SOLO_KREUZ => Kartenfarbe::KREUZ,
            default => null,
        };
    }

    /**
     * Ermittelt, ob in diesem Spiel tatsächlich ein Schweinchen bzw. Superschweinchen vorliegt.
     *
     * - Schweinchen: Regel aktiv UND ein Spieler hält beide Trumpffarb-Asse in der Starthand.
     * - Superschweinchen: zusätzlich Regel aktiv UND ein Spieler hält beide Trumpffarb-Neuner
     *   — nicht zwingend derselbe Spieler wie beim Schweinchen.
     *
     * Gilt für Normalspiel/Hochzeit (Karo) sowie Farb-Soli (Solo-Farbe). Buben-/Damen-/
     * Fleischlos-Solo haben keine Trumpffarbe und damit nie ein Schweinchen.
     *
     * @return array{schweinchen: bool, superschweinchen: bool}
     */
    public function schweinchenStatus(Spiel $spiel): array
    {
        // Null-Variante (Vorbehaltsrunde) wie Normalspiel behandeln: Trumpffarbe Karo.
        $variante = $spiel->getVariante() ?? SpielVariante::NORMALSPIEL;
        $farbe    = $this->trumpffarbe($variante);

        if ($farbe === null) {
            return ['schweinchen' => false, 'superschweinchen' => false];
        }

        $regel = $spiel->getTisch()->getRegelEinstellungen();
        $f     = $farbe->value;

        $schweinchen = !empty($regel['schweinchen'])
            && $this->einSpielerHaeltBeide($spiel, $f . '_ASS_1', $f . '_ASS_2');

        $superschweinchen = $schweinchen
            && !empty($regel['superschweinchen'])
            && $this->einSpielerHaeltBeide($spiel, $f . '_NEUN_1', $f . '_NEUN_2');

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
