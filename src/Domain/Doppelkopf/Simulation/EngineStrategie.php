<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\FortgeschritteneRegeln;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;

/**
 * Bindet die produktiven Entscheidungs-Engines in den Benchmark ein.
 *
 * Spiegelt die Glue-Logik der Application-Strategien ({@see \App\Application\Doppelkopf\Bot\HeuristikStrategie},
 * {@see \App\Application\Doppelkopf\Bot\FortgeschritteneStrategie}, {@see \App\Application\Doppelkopf\Bot\ProfiStrategie}
 * und {@see \App\Application\Doppelkopf\Bot\BotAnsage}) – nur werden die Fakten aus der
 * {@see Partie} statt aus der Datenbank gelesen. Die Engines selbst sind identisch, damit
 * der Benchmark misst, was auch am Tisch gespielt wird.
 *
 * Rollout-Zahl und Zeitbudget der PROFI-Engine sind hier bewusst frei setzbar, um genau
 * diesen Tuning-Punkt vermessen zu können.
 */
final class EngineStrategie implements SimulierteStrategie
{
    public function __construct(
        private readonly BotStaerke $staerke,
        private readonly BotHeuristik $anfaenger,
        private readonly FortgeschritteneHeuristik $fortgeschritten,
        private readonly ProfiHeuristik $profi,
        private readonly AnsageHeuristik $ansageHeuristik,
        private readonly int $rollouts = 60,
        private readonly float $zeitbudgetSek = 0.8,
        private readonly bool $ansagenAktiv = true,
        private readonly ?FortgeschritteneRegeln $regeln = null,
        private readonly ?AnsageSchwellen $ansageSchwellen = null,
    ) {}

    public function name(): string
    {
        $name = $this->staerke->value;
        if ($this->staerke === BotStaerke::PROFI) {
            $name .= $this->zeitbudgetSek >= 100.0
                ? sprintf('(r=%d)', $this->rollouts)
                : sprintf('(r=%d, t=%.2fs)', $this->rollouts, $this->zeitbudgetSek);
        }
        if ($this->staerke === BotStaerke::FORTGESCHRITTEN && $this->regeln !== null) {
            $name .= sprintf(' [%s]', $this->regeln->beschreibung());
        }

        if ($this->ansagenAktiv && $this->ansageSchwellen !== null) {
            $name .= sprintf(' [%s]', $this->ansageSchwellen->beschreibung());
        }

        return $this->ansagenAktiv ? $name : $name . ' ohne Ansagen';
    }

    public function waehleKarte(Partie $partie, int $sitzplatz, array $erlaubte): Karte
    {
        $ordnung = $partie->ordnung();
        $team    = $partie->eigenesTeam($sitzplatz);
        // Nur öffentlich bekannte Teams – kein Ausnutzen verdeckter Partnerschaft.
        $teams   = $partie->bekannteTeams($sitzplatz);

        if ($this->staerke === BotStaerke::ANFAENGER) {
            return $this->anfaenger->entscheide($erlaubte, $partie->offenerStich(), $team, $teams, $ordnung);
        }

        $gedaechtnis = $partie->gedaechtnisFuer($sitzplatz);

        if ($this->staerke === BotStaerke::FORTGESCHRITTEN) {
            return $this->fortgeschritten->entscheide(
                $erlaubte,
                $partie->offenerStich(),
                $team,
                $teams,
                $ordnung,
                $gedaechtnis,
                $this->regeln,
            );
        }

        return $this->profi->entscheide(
            $erlaubte,
            $partie->hand($sitzplatz),
            $sitzplatz,
            $team,
            $partie->offenerStich(),
            $partie->restHandGroessen($sitzplatz),
            $gedaechtnis->ungesehene(),
            $gedaechtnis,
            $partie->variante(),
            $teams,
            $partie->kreuzDameGespieltVon(),
            $ordnung,
            $partie->zweiteDulleSticht(),
            $this->rollouts,
            $this->zeitbudgetSek,
        );
    }

    public function willAnsagen(Partie $partie, int $sitzplatz): ?AnsageTyp
    {
        if (!$this->ansagenAktiv) {
            return null;
        }

        return $this->ansageHeuristik->willAnsagen(
            $this->staerke,
            $partie->hand($sitzplatz),
            $partie->eigenesTeam($sitzplatz),
            $partie->ordnung(),
            $this->ansageSchwellen,
            $this->teamAnsagen($partie, $sitzplatz),
            static fn(AnsageTyp $typ): int => AnsageRegeln::TIMING[$typ->value] ?? 0,
            $partie->starthand($sitzplatz),
            $partie->erspielteAugen($partie->eigenesTeam($sitzplatz)),
        );
    }

    /**
     * Was das eigene Team bereits angesagt hat – die Absagen bauen aufeinander auf.
     *
     * @return AnsageTyp[]
     */
    private function teamAnsagen(Partie $partie, int $sitzplatz): array
    {
        $team = $partie->eigenesTeam($sitzplatz);
        if ($team === null) {
            return [];
        }

        $teams   = $partie->teamProSitzplatz();
        $ansagen = [];
        foreach ($partie->ansagen() as $ansage) {
            if (($teams[$ansage['sitzplatz']] ?? null) === $team) {
                $ansagen[] = $ansage['typ'];
            }
        }

        return $ansagen;
    }
}
