<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\Team;

/**
 * Berechnet die detaillierte DDV-nahe Abrechnung eines beendeten Spiels.
 *
 * Punkteschema (vom Tischbetreiber festgelegt):
 *  - Gewonnen: +1 an Gewinnerpartei
 *  - Gegen die Alten: +1 (nur Normalspiel, wenn Kontra gewinnt)
 *  - keine 90/60/30 erreicht: je +1 an Gewinner (Verliereraugen unter Schwelle)
 *  - Schwarz erreicht: +1 (Verlierer ohne Stich)
 *  - Fuchs gefangen: +1 je erbeutetem gegnerischen Karo-Ass (nur Normalspiel)
 *  - Doppelkopf: +1 je Stich mit ≥ 40 Augen (nur Normalspiel)
 *  - Karlchen: +1 wenn der Kreuz-Bube den letzten Stich gewinnt (nur Normalspiel)
 *  - Re/Contra angesagt: je +2 an Gewinnerpartei
 *  - keine 90/60/30/Schwarz angesagt (Absage): je +1 an Gewinnerpartei
 *
 * Ansage-Punkte gehen IMMER an die Gewinnerpartei (auch wenn die ansagende Partei verliert).
 * Spielwert = Summe(Gewinner) − Summe(Verlierer); pro Spieler ±Spielwert, Solist ×3.
 */
final class WertungsRechner
{
    private const DOPPELKOPF_AUGEN = 40;

    /** Punktwert je Ansage-Typ (an die Gewinnerpartei). */
    private const ANSAGE_PUNKTE = [
        'RE'          => 2,
        'CONTRA'      => 2,
        'KEINE_NEUN'  => 1,
        'KEINE_SECHS' => 1,
        'KEINE_DREI'  => 1,
        'SCHWARZ'     => 1,
    ];

    public function __construct(
        private readonly StichGewinner $stichGewinner,
    ) {}

    /**
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $stiche  Stiche in Spielreihenfolge, je Stich die Karten in Ausspielreihenfolge
     * @param array<int, Team>                                $teamProSitzplatz
     * @param list<array{sitzplatz: int, typ: AnsageTyp}>     $ansagen
     * @param int|null                                        $solistSitzplatz  gesetzt bei Solo, sonst null
     *
     * @return array{
     *     augen: array<string, int>,
     *     stiche: array<string, int>,
     *     sieger: string,
     *     positionen: list<array{label: string, team: string, punkte: int}>,
     *     summeProTeam: array<string, int>,
     *     spielwert: int
     * }
     */
    public function berechne(
        array $stiche,
        array $teamProSitzplatz,
        TrumpfOrdnung $ordnung,
        array $ansagen,
        bool $zweiteDulleSticht,
        ?int $solistSitzplatz = null,
    ): array {
        $istSolo = $solistSitzplatz !== null;

        $augen  = [Team::RE->value => 0, Team::KONTRA->value => 0];
        $sticheProTeam = [Team::RE->value => 0, Team::KONTRA->value => 0];
        $fuchs  = [Team::RE->value => 0, Team::KONTRA->value => 0];
        $doppelkopf = [Team::RE->value => 0, Team::KONTRA->value => 0];
        $karlchen = [Team::RE->value => 0, Team::KONTRA->value => 0];

        $anzahlStiche = count($stiche);

        foreach ($stiche as $index => $stich) {
            $kartenFuerGewinner = [];
            $stichAugen = 0;
            foreach ($stich as $eintrag) {
                $kartenFuerGewinner[$eintrag['sitzplatz']] = $eintrag['karte'];
                $stichAugen += $eintrag['karte']->augen();
            }

            $gewinnerSitzplatz = $this->stichGewinner->bestimme($kartenFuerGewinner, $ordnung, $zweiteDulleSticht);
            $gewinnerTeam = $teamProSitzplatz[$gewinnerSitzplatz] ?? null;
            if ($gewinnerTeam === null) {
                continue;
            }

            $augen[$gewinnerTeam->value] += $stichAugen;
            $sticheProTeam[$gewinnerTeam->value]++;

            if (!$istSolo) {
                // Doppelkopf: Stich mit ≥ 40 Augen
                if ($stichAugen >= self::DOPPELKOPF_AUGEN) {
                    $doppelkopf[$gewinnerTeam->value]++;
                }

                // Fuchs gefangen: gegnerisches Karo-Ass im Stich erbeutet
                foreach ($stich as $eintrag) {
                    if (!$this->istFuchs($eintrag['karte'], $ordnung)) {
                        continue;
                    }
                    $besitzerTeam = $teamProSitzplatz[$eintrag['sitzplatz']] ?? null;
                    if ($besitzerTeam !== null && $besitzerTeam !== $gewinnerTeam) {
                        $fuchs[$gewinnerTeam->value]++;
                    }
                }

                // Karlchen: Kreuz-Bube gewinnt den letzten Stich
                if ($index === $anzahlStiche - 1) {
                    $siegKarte = $kartenFuerGewinner[$gewinnerSitzplatz];
                    if ($siegKarte->farbe === Kartenfarbe::KREUZ && $siegKarte->wert === Kartenwert::BUBE) {
                        $karlchen[$gewinnerTeam->value]++;
                    }
                }
            }
        }

        $reAugen = $augen[Team::RE->value];
        $sieger  = $reAugen >= 121 ? Team::RE : Team::KONTRA;
        $verlierer = $sieger === Team::RE ? Team::KONTRA : Team::RE;
        $verliererAugen  = $augen[$verlierer->value];
        $verliererStiche = $sticheProTeam[$verlierer->value];

        $positionen = [];

        // ── Grundpunkte (an Gewinner) ──────────────────────────────────────
        $positionen[] = $this->position('Gewonnen', $sieger, 1);

        if (!$istSolo && $sieger === Team::KONTRA) {
            $positionen[] = $this->position('Gegen die Alten', $sieger, 1);
        }
        if ($verliererAugen < 90) {
            $positionen[] = $this->position('keine 90', $sieger, 1);
        }
        if ($verliererAugen < 60) {
            $positionen[] = $this->position('keine 60', $sieger, 1);
        }
        if ($verliererAugen < 30) {
            $positionen[] = $this->position('keine 30', $sieger, 1);
        }
        if ($verliererStiche === 0) {
            $positionen[] = $this->position('Schwarz', $sieger, 1);
        }

        // ── Sonderpunkte (an die jeweils erzielende Partei) ────────────────
        foreach ([Team::RE, Team::KONTRA] as $team) {
            if ($fuchs[$team->value] > 0) {
                $positionen[] = $this->position(
                    'Fuchs gefangen' . ($fuchs[$team->value] > 1 ? ' (' . $fuchs[$team->value] . '×)' : ''),
                    $team,
                    $fuchs[$team->value],
                );
            }
            if ($doppelkopf[$team->value] > 0) {
                $positionen[] = $this->position(
                    'Doppelkopf' . ($doppelkopf[$team->value] > 1 ? ' (' . $doppelkopf[$team->value] . '×)' : ''),
                    $team,
                    $doppelkopf[$team->value],
                );
            }
            if ($karlchen[$team->value] > 0) {
                $positionen[] = $this->position('Karlchen', $team, $karlchen[$team->value]);
            }
        }

        // ── Ansagen (immer an Gewinner) ────────────────────────────────────
        foreach ($this->ansagePunkte($ansagen, $teamProSitzplatz) as $eintrag) {
            $positionen[] = $this->position($eintrag['label'], $sieger, $eintrag['punkte']);
        }

        // ── Summen & Spielwert ─────────────────────────────────────────────
        $summeProTeam = [Team::RE->value => 0, Team::KONTRA->value => 0];
        foreach ($positionen as $p) {
            $summeProTeam[$p['team']] += $p['punkte'];
        }
        $spielwert = $summeProTeam[$sieger->value] - $summeProTeam[$verlierer->value];

        return [
            'augen'        => $augen,
            'stiche'       => $sticheProTeam,
            'sieger'       => $sieger->value,
            'positionen'   => $positionen,
            'summeProTeam' => $summeProTeam,
            'spielwert'    => $spielwert,
        ];
    }

    /** Ein Fuchs ist das Karo-Ass, sofern es im aktuellen Spiel Trumpf ist (also nicht in Soli ohne Karo-Trumpf). */
    private function istFuchs(Karte $karte, TrumpfOrdnung $ordnung): bool
    {
        return $karte->farbe === Kartenfarbe::KARO
            && $karte->wert === Kartenwert::ASS
            && $ordnung->istTrumpf($karte);
    }

    /**
     * Dedupliziert Ansagen pro (Partei, Typ) und summiert deren Punktwerte als Positions-Vorlagen.
     *
     * @param list<array{sitzplatz: int, typ: AnsageTyp}> $ansagen
     * @param array<int, Team>                            $teamProSitzplatz
     * @return list<array{label: string, punkte: int}>
     */
    private function ansagePunkte(array $ansagen, array $teamProSitzplatz): array
    {
        $gesehen = [];
        $ergebnis = [];

        foreach ($ansagen as $ansage) {
            $typ = $ansage['typ']->value;
            if (!isset(self::ANSAGE_PUNKTE[$typ])) {
                continue; // HOCHZEIT/SOLO sind keine Wertungs-Ansagen
            }
            $team = $teamProSitzplatz[$ansage['sitzplatz']] ?? null;
            if ($team === null) {
                continue;
            }

            $schluessel = $team->value . ':' . $typ;
            if (isset($gesehen[$schluessel])) {
                continue;
            }
            $gesehen[$schluessel] = true;

            $ergebnis[] = [
                'label'  => $this->ansageLabel($typ),
                'punkte' => self::ANSAGE_PUNKTE[$typ],
            ];
        }

        return $ergebnis;
    }

    private function ansageLabel(string $typ): string
    {
        return match ($typ) {
            'RE'          => 'Re angesagt',
            'CONTRA'      => 'Contra angesagt',
            'KEINE_NEUN'  => 'Absage: keine 90',
            'KEINE_SECHS' => 'Absage: keine 60',
            'KEINE_DREI'  => 'Absage: keine 30',
            'SCHWARZ'     => 'Absage: Schwarz',
            default       => $typ,
        };
    }

    /** @return array{label: string, team: string, punkte: int} */
    private function position(string $label, Team $team, int $punkte): array
    {
        return ['label' => $label, 'team' => $team->value, 'punkte' => $punkte];
    }
}
