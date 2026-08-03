<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Enum\AnsageTyp;
use App\Enum\Team;

/**
 * Ansage-Regeln (Timing + Stacking) als reine Prüfung – für den Self-Play-Benchmark,
 * der ohne Datenbank läuft.
 *
 * Die Tabellen spiegeln bewusst {@see \App\Application\Doppelkopf\AnsageService}::TIMING
 * bzw. ::VORAUSSETZUNG; ein Test (AnsageRegelnTest) vergleicht beide per Reflection,
 * damit sie nicht auseinanderlaufen. Sobald die Bots im Produktivpfad auch Absagen
 * machen sollen (P5 Hebel 5), gehört diese Klasse zur gemeinsamen Quelle beider Pfade
 * ausgebaut und im AnsageService verwendet.
 *
 * Nicht abgebildet: der Hochzeits-Nachlass auf die Fristen (TSR 6.4.2) – der Benchmark
 * spielt vorerst nur Normalspiele.
 */
final class AnsageRegeln
{
    /** Mindestanzahl verbleibender Handkarten je Ansage-Typ. */
    public const TIMING = [
        'RE'          => 11,
        'CONTRA'      => 11,
        'KEINE_NEUN'  => 10,
        'KEINE_SECHS' => 9,
        'KEINE_DREI'  => 8,
        'SCHWARZ'     => 7,
    ];

    /** @var array<string, string[]> Welche Ansage muss das Team vorher gemacht haben? */
    public const VORAUSSETZUNG = [
        'KEINE_NEUN'  => ['RE', 'CONTRA'],
        'KEINE_SECHS' => ['KEINE_NEUN'],
        'KEINE_DREI'  => ['KEINE_SECHS'],
        'SCHWARZ'     => ['KEINE_DREI'],
    ];

    /**
     * @param list<array{sitzplatz: int, typ: AnsageTyp}> $bisherigeAnsagen
     * @param array<int, Team>                            $teamProSitzplatz
     */
    public function erlaubt(
        AnsageTyp $typ,
        int $sitzplatz,
        int $kartenInHand,
        array $bisherigeAnsagen,
        array $teamProSitzplatz,
    ): bool {
        if (!isset(self::TIMING[$typ->value])) {
            return false; // HOCHZEIT/SOLO sind keine Ansagen im Wertungssinn
        }

        $team = $teamProSitzplatz[$sitzplatz] ?? null;
        if ($team === null) {
            return false;
        }
        if ($typ === AnsageTyp::RE && $team !== Team::RE) {
            return false;
        }
        if ($typ === AnsageTyp::CONTRA && $team !== Team::KONTRA) {
            return false;
        }

        if ($kartenInHand < self::TIMING[$typ->value]) {
            return false; // zu spät
        }

        foreach ($bisherigeAnsagen as $ansage) {
            if ($ansage['sitzplatz'] === $sitzplatz && $ansage['typ'] === $typ) {
                return false; // Dopplung
            }
        }

        if (isset(self::VORAUSSETZUNG[$typ->value])) {
            $vomTeam = [];
            foreach ($bisherigeAnsagen as $ansage) {
                if (($teamProSitzplatz[$ansage['sitzplatz']] ?? null) === $team) {
                    $vomTeam[$ansage['typ']->value] = true;
                }
            }

            foreach (self::VORAUSSETZUNG[$typ->value] as $vorWert) {
                if (isset($vomTeam[$vorWert])) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
