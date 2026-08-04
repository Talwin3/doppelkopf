<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\Team;

/**
 * Entscheidet, ob ein Bot früh im Spiel "Re" bzw. "Contra" ansagt (verdoppelt den
 * Spielwert). Reine Logik ohne DB.
 *
 * Angesagt wird nur bei deutlich starker Hand (viele Trümpfe + hohe Trümpfe), damit
 * Bots die Ansage nicht verschenken – für Contra strenger als für Re, weil KONTRA
 * strukturell schwächer steht ({@see AnsageSchwellen}). Das eigene Team ist dem Bot fair
 * bekannt (eigene Karten) – die Ansage deckt es ohnehin auf. Anfänger sagen nie an.
 */
final class AnsageHeuristik
{
    /**
     * @param Karte[]      $hand         Aktuelle Handkarten
     * @param ?AnsageSchwellen $schwellen Mindeststärke je Partei (null = Standard)
     * @param AnsageTyp[]  $teamAnsagen  Was das eigene Team bereits angesagt hat
     * @return ?AnsageTyp Nächste sinnvolle Ansage, oder null
     */
    public function willAnsagen(
        BotStaerke $staerke,
        array $hand,
        ?Team $team,
        TrumpfOrdnung $ordnung,
        ?AnsageSchwellen $schwellen = null,
        array $teamAnsagen = [],
    ): ?AnsageTyp {
        if ($staerke === BotStaerke::ANFAENGER || $team === null) {
            return null;
        }

        $schwellen ??= new AnsageSchwellen();

        $truempfe = 0;
        $damen    = 0;
        $kreuzDamen = 0;
        foreach ($hand as $k) {
            if ($ordnung->istTrumpf($k)) {
                $truempfe++;
            }
            if ($k->wert === Kartenwert::DAME) {
                $damen++;
                if ($k->farbe === Kartenfarbe::KREUZ) {
                    $kreuzDamen++;
                }
            }
        }

        // Hohe Trümpfe sind Voraussetzung für jede Ansage.
        if ($kreuzDamen < 1 && $damen < 2) {
            return null;
        }

        $grundansage = $team === Team::RE ? AnsageTyp::RE : AnsageTyp::CONTRA;

        if (!in_array($grundansage, $teamAnsagen, true)) {
            return $truempfe >= $schwellen->minTruempfe($team) ? $grundansage : null;
        }

        // Grundansage steht – die Absagen der Reihe nach anbieten. Jede setzt die vorige
        // voraus, deshalb zählt nur die erste noch offene Stufe.
        foreach (AnsageSchwellen::ABSAGE_KETTE as $absage) {
            if (in_array($absage, $teamAnsagen, true)) {
                continue;
            }

            return $truempfe >= $schwellen->minTruempfeFuerAbsage($absage) ? $absage : null;
        }

        return null;
    }
}
