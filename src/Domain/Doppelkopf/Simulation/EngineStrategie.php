<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\ValueObject\AnsageAussichten;
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\FortgeschritteneRegeln;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;
use App\Enum\EndspielPolitik;

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
        private readonly bool $punkteBewertung = false,
        private readonly bool $nurZweiGegenZwei = true,
        private readonly int $endspielAbRestkarten = 0,
        private readonly bool $endspielDiagnose = false,
        private readonly EndspielPolitik $endspielPolitik = EndspielPolitik::MINIMAX,
        private readonly bool $bewertungsDiagnose = false,
        private readonly bool $teamNebenbedingung = true,
        private readonly float $schmierEvidenz = 0.0,
    ) {}

    public function name(): string
    {
        $name = $this->staerke->value;
        if ($this->staerke === BotStaerke::PROFI) {
            $name .= $this->zeitbudgetSek >= 100.0
                ? sprintf('(r=%d)', $this->rollouts)
                : sprintf('(r=%d, t=%.2fs)', $this->rollouts, $this->zeitbudgetSek);
            $name .= $this->punkteBewertung ? ' [Punkte]' : ' [Augen]';
            if (!$this->nurZweiGegenZwei) {
                $name .= ' [1-gegen-3-Welten]';
            }
            if (!$this->teamNebenbedingung) {
                $name .= ' [ohne Kreuz-Damen-Nebenbedingung]';
            }
            if ($this->schmierEvidenz > 0.0) {
                $name .= sprintf(' [Schmier-Evidenz %.2f]', $this->schmierEvidenz);
            }
            if ($this->endspielAbRestkarten > 0) {
                $name .= sprintf(' [Endspiel exakt ab %d Karten]', $this->endspielAbRestkarten);
                if ($this->endspielPolitik !== EndspielPolitik::MINIMAX) {
                    $name .= sprintf(' [%s]', $this->endspielPolitik->label());
                }
            }
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
            erlaubte: $erlaubte,
            eigeneHand: $partie->hand($sitzplatz),
            eigenerSitz: $sitzplatz,
            eigenesTeam: $team,
            offenerStich: $partie->offenerStich(),
            restHandGroessen: $partie->restHandGroessen($sitzplatz),
            ungesehene: $gedaechtnis->ungesehene(),
            gedaechtnis: $gedaechtnis,
            variante: $partie->variante(),
            bekannteTeams: $teams,
            kreuzDameGespieltVon: $partie->kreuzDameGespieltVon(),
            ordnung: $ordnung,
            zweiteDulle: $partie->zweiteDulleSticht(),
            // Die Wertungsschwellen gelten fürs ganze Spiel – die schon gelaufenen
            // Stiche und Ansagen gehören deshalb in die Bewertung der Welt.
            bisherigeStiche: $partie->abgeschlosseneStiche(),
            ansagen: $partie->ansagen(),
            // Der Benchmark simuliert nur Normalspiele, also nie einen Solisten.
            solistSitzplatz: null,
            bewertePunkte: $this->punkteBewertung,
            nurZweiGegenZwei: $this->nurZweiGegenZwei,
            rollouts: $this->rollouts,
            zeitbudgetSek: $this->zeitbudgetSek,
            endspielAbRestkarten: $this->endspielAbRestkarten,
            endspielDiagnose: $this->endspielDiagnose,
            endspielPolitik: $this->endspielPolitik,
            bewertungsDiagnose: $this->bewertungsDiagnose,
            teamNebenbedingung: $this->teamNebenbedingung,
            schmierEvidenz: $this->schmierEvidenz,
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
            $this->aussichtenGeber($partie, $sitzplatz),
        );
    }

    /**
     * Rollout-Schätzer für die Ansage – nur PROFI hat die dafür nötige PIMC-Engine.
     *
     * @return ?callable(AnsageTyp[]): AnsageAussichten
     */
    private function aussichtenGeber(Partie $partie, int $sitzplatz): ?callable
    {
        $rollout = $this->ansageSchwellen?->rollout;
        if ($rollout === null || $this->staerke !== BotStaerke::PROFI) {
            return null;
        }

        return function (array $kandidaten) use ($partie, $sitzplatz, $rollout): AnsageAussichten {
            $gedaechtnis = $partie->gedaechtnisFuer($sitzplatz);

            return $this->profi->aussichten(
                kandidaten: $kandidaten,
                eigeneHand: $partie->hand($sitzplatz),
                eigenerSitz: $sitzplatz,
                eigenesTeam: $partie->eigenesTeam($sitzplatz),
                offenerStich: $partie->offenerStich(),
                restHandGroessen: $partie->restHandGroessen($sitzplatz),
                ungesehene: $gedaechtnis->ungesehene(),
                gedaechtnis: $gedaechtnis,
                variante: $partie->variante(),
                bekannteTeams: $partie->bekannteTeams($sitzplatz),
                kreuzDameGespieltVon: $partie->kreuzDameGespieltVon(),
                ordnung: $partie->ordnung(),
                zweiteDulle: $partie->zweiteDulleSticht(),
                bisherigeStiche: $partie->abgeschlosseneStiche(),
                ansagen: $partie->ansagen(),
                solistSitzplatz: null,
                nurZweiGegenZwei: $this->nurZweiGegenZwei,
                rollouts: $rollout->welten,
                zeitbudgetSek: $this->zeitbudgetSek,
                teamNebenbedingung: $this->teamNebenbedingung,
            );
        };
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
