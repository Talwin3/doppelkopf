<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\EvidenzStatistik;
use App\Domain\Doppelkopf\Service\TeamEvidenz;

/**
 * Hält die weichen Team-Signale ({@see TeamEvidenz}) gegen die Wahrheit.
 *
 * Nur im Benchmark möglich und nur dort erlaubt: die echten Parteien stehen ausschließlich in
 * der {@see Partie}, ein Bot am Tisch kennt sie nicht. Deshalb liegt diese Klasse in der
 * Simulation und nicht im Domain-Service – und deshalb läuft sie **nach** dem Spiel, wenn keine
 * Entscheidung mehr davon abhängen kann.
 *
 * Sie beantwortet die Frage, die vor jedem teuren Messlauf steht (Messfalle 6): kann der Hebel
 * überhaupt etwas bewirken? Für weiche Evidenz zerfällt das in zwei Teile – **wie oft** liegt
 * eine Aussage vor, die über den harten Stand von Schritt 1 hinausgeht, und **wie zuverlässig**
 * ist sie. Ein häufiges, aber unzuverlässiges Signal ist genauso wertlos wie ein zuverlässiges,
 * das nie feuert.
 *
 * Per Default abgeschaltet; das Turnier-Kommando schaltet sie mit `--evidenz-diagnose` an.
 */
final class EvidenzDiagnose
{
    public readonly EvidenzStatistik $statistik;

    private bool $aktiv = false;

    public function __construct(private readonly TeamEvidenz $evidenz)
    {
        $this->statistik = new EvidenzStatistik();
    }

    public function aktivieren(): void
    {
        $this->aktiv = true;
    }

    public function istAktiv(): bool
    {
        return $this->aktiv;
    }

    /**
     * Wertet den soeben abgeschlossenen Stich aus – einmal aus der Sicht jedes der vier Sitze.
     *
     * Der Zeitpunkt ist entscheidend: **direkt nach dem Stich**, nicht am Spielende. Bis zum
     * Ende sind fast alle Kreuz-Damen gefallen, `bekannteTeams()` wäre dann nahezu vollständig
     * und jede Aussage sähe überflüssig aus. Gemessen werden muss der Stand, in dem die
     * Determinisierung tatsächlich arbeitet.
     *
     * Die Paar-Aussagen selbst sind beobachterunabhängig (sie stehen im öffentlichen Verlauf);
     * beobachterabhängig ist nur, ob eine Aussage noch etwas Neues sagt. Ein Sitz, dessen
     * Gegenüber ohnehin schon angesagt hat, gewinnt aus demselben Signal nichts mehr.
     */
    public function beobachte(Partie $partie): void
    {
        if (!$this->aktiv) {
            return;
        }

        $stiche = $partie->abgeschlosseneStiche();
        if ($stiche === []) {
            return;
        }

        $letzterNr = array_key_last($stiche);
        $wahr      = $partie->teamProSitzplatz();
        $aussagen  = $this->evidenz->fuerStich($stiche[$letzterNr], $letzterNr, $partie->ordnung());

        for ($beobachter = 1; $beobachter <= 4; $beobachter++) {
            $bekannt = $partie->bekannteTeams($beobachter);

            $this->statistik->sichten++;
            $neueAussagen = 0;

            foreach ($bekannt as $sitz => $team) {
                if ($sitz === $beobachter) {
                    continue;
                }
                $this->statistik->fremdeSitze++;
                if ($team !== null) {
                    $this->statistik->fremdeSitzeHartBekannt++;
                }
            }

            foreach ($aussagen as $aussage) {
                $a = $aussage['a'];
                $b = $aussage['b'];

                // Was der Beobachter selbst ist, weiß er immer – für alle anderen zählt nur,
                // was öffentlich ist.
                $teamA = $a === $beobachter ? $wahr[$a] : ($bekannt[$a] ?? null);
                $teamB = $b === $beobachter ? $wahr[$b] : ($bekannt[$b] ?? null);

                $neu = $teamA === null || $teamB === null;

                $richtig = ($wahr[$a] === $wahr[$b]) === $aussage['gleichesTeam'];

                $this->statistik->verbuche($aussage['signal'], $richtig, $neu, $aussage['stichNr'], $aussage['gleichesTeam']);
                if ($neu) {
                    $neueAussagen++;
                }
            }

            if ($neueAussagen > 0) {
                $this->statistik->sichtenMitNeuerAussage++;
            }
        }
    }
}
