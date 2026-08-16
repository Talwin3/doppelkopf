<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\LegaleKarten;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\Service\WertungsRechner;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielVariante;
use App\Enum\Team;

/**
 * Spielt eine komplette Partie headless (ohne Datenbank, ohne Doctrine) und rechnet sie
 * mit dem produktiven {@see WertungsRechner} ab. Grundlage des Self-Play-Benchmarks
 * ({@see TurnierLauf}), mit dem sich Änderungen an den Bot-Engines messen lassen.
 *
 * Umfang: **Normalspiel**. Vorbehalte (Solo/Hochzeit/Armut) werden nicht simuliert –
 * Blätter, in denen ein Spieler beide Kreuz-Damen hält (stille Hochzeit), meldet
 * {@see teamsAusKreuzDamen()} als null und der Aufruf überspringt sie.
 */
final class SpielSimulator
{
    private const KARTEN_PRO_STICH = 4;

    public function __construct(
        private readonly StichGewinner $stichGewinner,
        private readonly WertungsRechner $wertungsRechner,
        private readonly AnsageRegeln $ansageRegeln,
        private readonly EvidenzDiagnose $evidenzDiagnose,
        private readonly AnsageHeuristik $ansageHeuristik,
    ) {}

    /**
     * Leitet die Parteien aus den Kreuz-Damen ab (Normalspiel).
     *
     * @param array<int, Karte[]> $starthaende
     * @return array<int, Team>|null null, wenn ein Spieler beide Kreuz-Damen hält
     *                               (stille Hochzeit – kein reguläres Normalspiel)
     */
    public static function teamsAusKreuzDamen(array $starthaende): ?array
    {
        $teams = [];
        foreach ($starthaende as $sitz => $hand) {
            $kreuzDamen = 0;
            foreach ($hand as $karte) {
                if ($karte->farbe === Kartenfarbe::KREUZ && $karte->wert === Kartenwert::DAME) {
                    $kreuzDamen++;
                }
            }
            if ($kreuzDamen === 2) {
                return null;
            }
            $teams[$sitz] = $kreuzDamen === 1 ? Team::RE : Team::KONTRA;
        }

        return $teams;
    }

    /**
     * @param array<int, Karte[]>             $starthaende Sitzplatz 1–4 → Handkarten
     * @param array<int, SimulierteStrategie> $strategien  Sitzplatz 1–4 → Strategie
     * @param array<int, Team>                $teamProSitzplatz
     * @param int                             $vorhand     Sitzplatz, der den ersten Stich anspielt
     */
    public function spiele(
        array $starthaende,
        array $strategien,
        array $teamProSitzplatz,
        TrumpfOrdnung $ordnung,
        bool $zweiteDulleSticht = false,
        int $vorhand = 1,
    ): SpielErgebnis {
        $partie = new Partie($starthaende, $teamProSitzplatz, $ordnung, SpielVariante::NORMALSPIEL, $zweiteDulleSticht);

        $amZug = $vorhand;
        while (!$partie->vorbei()) {
            $strategie = $strategien[$amZug] ?? throw new \LogicException("Keine Strategie für Sitzplatz {$amZug}.");

            $this->pruefeAnsage($partie, $amZug, $strategie);

            $erlaubte = LegaleKarten::fuer($partie->hand($amZug), $partie->angespielteKarte(), $ordnung);
            if ($erlaubte === []) {
                throw new \LogicException("Sitzplatz {$amZug} hat keine spielbare Karte.");
            }

            $karte = $strategie->waehleKarte($partie, $amZug, $erlaubte);
            if (!$this->istUnterDen($karte, $erlaubte)) {
                throw new \LogicException(sprintf(
                    'Strategie "%s" wählte die unerlaubte Karte %s.',
                    $strategie->name(),
                    $karte->id(),
                ));
            }

            $partie->spieleKarte($amZug, $karte);

            if ($partie->stichVollstaendig()) {
                $amZug = $this->stichGewinner->bestimme($partie->offenerStich(), $ordnung, $zweiteDulleSticht);
                $partie->schliesseStich($amZug);
                // Diagnose, per Default aus – misst die weichen Team-Signale gegen die Wahrheit.
                $this->evidenzDiagnose->beobachte($partie);
                continue;
            }

            $amZug = ($amZug % self::KARTEN_PRO_STICH) + 1;
        }

        return $this->rechneAb($partie);
    }

    private function pruefeAnsage(Partie $partie, int $sitzplatz, SimulierteStrategie $strategie): void
    {
        $typ = $strategie->willAnsagen($partie, $sitzplatz);
        if ($typ === null) {
            return;
        }

        $erlaubt = $this->ansageRegeln->erlaubt(
            $typ,
            $sitzplatz,
            $partie->kartenInHand($sitzplatz),
            $partie->ansagen(),
            $partie->teamProSitzplatz(),
        );

        if ($erlaubt) {
            $partie->fuegeAnsageHinzu($sitzplatz, $typ);
        }
    }

    /** Hat die Re-Partei „Re" angesagt? Verschiebt die Augengrenze der Contra-Ansage auf 120. */
    private function reHatAngesagt(Partie $partie): bool
    {
        $teams = $partie->teamProSitzplatz();

        foreach ($partie->ansagen() as $ansage) {
            if ($ansage['typ'] === AnsageTyp::RE && ($teams[$ansage['sitzplatz']] ?? null) === Team::RE) {
                return true;
            }
        }

        return false;
    }

    private function rechneAb(Partie $partie): SpielErgebnis
    {
        $wertung = $this->wertungsRechner->berechne(
            $partie->abgeschlosseneStiche(),
            $partie->teamProSitzplatz(),
            $partie->ordnung(),
            $partie->ansagen(),
            $partie->zweiteDulleSticht(),
        );

        // Diagnose, per Default aus: die Abfragen dieses Blattes bekommen ihren Ausgang. Erst
        // damit lässt sich zu jeder Schwelle die Haltequote ausrechnen. Ob Re angesagt hat,
        // entscheidet über die Augengrenze der Contra-Ansage (TSR 7.1.1.3).
        $this->ansageHeuristik->statistik->blattAbschliessen(
            $wertung['augen'],
            $this->reHatAngesagt($partie),
        );

        // Spielwert geht an den Empfänger, die Gegenpartei zahlt ihn (pro Spieler).
        $empfaenger = $wertung['punkteEmpfaenger'];
        $punkte     = [];
        foreach ($partie->teamProSitzplatz() as $sitz => $team) {
            $punkte[$sitz] = $team->value === $empfaenger ? $wertung['spielwert'] : -$wertung['spielwert'];
        }

        return new SpielErgebnis(
            $punkte,
            $partie->teamProSitzplatz(),
            $wertung['augen'],
            $wertung['sieger'],
            $wertung['spielwert'],
            $partie->ansagen(),
            $wertung,
        );
    }

    /** @param Karte[] $erlaubte */
    private function istUnterDen(Karte $karte, array $erlaubte): bool
    {
        foreach ($erlaubte as $k) {
            if ($k->id() === $karte->id()) {
                return true;
            }
        }

        return false;
    }
}
