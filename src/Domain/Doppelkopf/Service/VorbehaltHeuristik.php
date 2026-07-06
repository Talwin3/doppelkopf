<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Domain\Doppelkopf\ValueObject\VorbehaltEntscheidung;
use App\Enum\BotStaerke;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielVariante;
use App\Enum\VorbehaltTyp;

/**
 * Bot-Entscheidung in der Vorbehaltsrunde – reine Logik ohne DB.
 *
 * Reihenfolge: Hochzeit (Pflicht bei zwei Kreuz-Damen) > Solo > Armut > Gesund.
 * Solo wird nur ab {@see BotStaerke::FORTGESCHRITTEN} und nur bei sehr trumpfstarker
 * Hand erwogen (konservativ, damit Bots keine aussichtslosen Soli spielen). Anfänger
 * verhalten sich wie bisher (kein Solo).
 */
final class VorbehaltHeuristik
{
    /**
     * Wählt aus den erlaubten Farbsoli ab dieser Trumpfzahl (bezogen auf die Handgröße)
     * eines aus: höchstens zwei Nicht-Trümpfe auf der Hand.
     */
    private const MAX_NICHT_TRUMPF_FUER_SOLO = 2;

    /**
     * @param Karte[]            $hand
     * @param array<string, int> $soloTrumpfAnzahl Erlaubte (Farb-)Soli: Varianten-Wert → Anzahl Trümpfe der Hand in dieser Variante
     */
    public function entscheide(
        BotStaerke $staerke,
        array $hand,
        bool $armutErlaubt,
        int $trumpfAnzahlNormal,
        array $soloTrumpfAnzahl,
    ): VorbehaltEntscheidung {
        // Zwei Kreuz-Damen → Hochzeit (wie bisher, Pflicht-nahe Ansage).
        $kreuzDamen = 0;
        foreach ($hand as $k) {
            if ($k->farbe === Kartenfarbe::KREUZ && $k->wert === Kartenwert::DAME) {
                $kreuzDamen++;
            }
        }
        if ($kreuzDamen === 2) {
            return new VorbehaltEntscheidung(VorbehaltTyp::HOCHZEIT);
        }

        // Solo nur für stärkere Bots und nur bei trumpfstarker Hand.
        if ($staerke !== BotStaerke::ANFAENGER && $soloTrumpfAnzahl !== []) {
            $schwelle = max(0, count($hand) - self::MAX_NICHT_TRUMPF_FUER_SOLO);

            $besteVariante = null;
            $besteAnzahl   = -1;
            foreach ($soloTrumpfAnzahl as $wert => $anzahl) {
                if ($anzahl > $besteAnzahl) {
                    $besteAnzahl   = $anzahl;
                    $besteVariante = $wert;
                }
            }

            if ($besteVariante !== null && $besteAnzahl >= $schwelle) {
                $variante = SpielVariante::tryFrom($besteVariante);
                if ($variante !== null) {
                    return VorbehaltEntscheidung::solo($variante);
                }
            }
        }

        // Armut: wenige Trümpfe + Regel aktiv.
        if ($armutErlaubt && $trumpfAnzahlNormal <= 3) {
            return new VorbehaltEntscheidung(VorbehaltTyp::ARMUT);
        }

        return VorbehaltEntscheidung::gesund();
    }
}
