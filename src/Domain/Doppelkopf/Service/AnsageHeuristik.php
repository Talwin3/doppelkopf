<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

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
 * Bots die Ansage nicht verschenken. Das eigene Team ist dem Bot fair bekannt
 * (eigene Karten) – die Ansage deckt es ohnehin auf. Anfänger sagen nie an.
 */
final class AnsageHeuristik
{
    private const MIN_TRUEMPFE = 7;

    /**
     * @param Karte[] $hand Aktuelle Handkarten
     * @return ?AnsageTyp RE/CONTRA, oder null wenn (noch) nicht ansagen
     */
    public function willAnsagen(BotStaerke $staerke, array $hand, ?Team $team, TrumpfOrdnung $ordnung): ?AnsageTyp
    {
        if ($staerke === BotStaerke::ANFAENGER || $team === null) {
            return null;
        }

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

        // Starke Hand: genügend Trümpfe und hohe Trümpfe (Kreuz-Dame oder mehrere Damen).
        $stark = $truempfe >= self::MIN_TRUEMPFE && ($kreuzDamen >= 1 || $damen >= 2);
        if (!$stark) {
            return null;
        }

        return $team === Team::RE ? AnsageTyp::RE : AnsageTyp::CONTRA;
    }
}
