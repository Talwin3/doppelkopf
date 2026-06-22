<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;

/**
 * Ermittelt die im laufenden Spiel bereits gewonnenen Stiche je Sitzplatz
 * (Anzahl + Augensumme) — Grundlage für die verdeckten Stich-Stapel am Tisch.
 */
final class StichStatistik
{
    public function __construct(
        private readonly StichGewinner $stichGewinner,
    ) {}

    /**
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $vollstaendigeStiche  nur abgeschlossene Stiche (4 Karten)
     * @return array<int, array{anzahl: int, augen: int}>  je Sitzplatz die gewonnenen Stiche
     */
    public function gewonneneStiche(array $vollstaendigeStiche, TrumpfOrdnung $ordnung, bool $zweiteDulleSticht): array
    {
        $ergebnis = [];

        foreach ($vollstaendigeStiche as $stich) {
            $kartenFuerGewinner = [];
            $augen = 0;
            foreach ($stich as $eintrag) {
                $kartenFuerGewinner[$eintrag['sitzplatz']] = $eintrag['karte'];
                $augen += $eintrag['karte']->augen();
            }

            $gewinner = $this->stichGewinner->bestimme($kartenFuerGewinner, $ordnung, $zweiteDulleSticht);

            if (!isset($ergebnis[$gewinner])) {
                $ergebnis[$gewinner] = ['anzahl' => 0, 'augen' => 0];
            }
            $ergebnis[$gewinner]['anzahl']++;
            $ergebnis[$gewinner]['augen'] += $augen;
        }

        return $ergebnis;
    }
}
