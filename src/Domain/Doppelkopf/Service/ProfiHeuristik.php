<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielVariante;
use App\Enum\Team;

/**
 * Stufe {@see \App\Enum\BotStaerke::PROFI}: Kartenwahl per Perfect-Information Monte Carlo (PIMC).
 *
 * Für jede erlaubte Karte werden mehrere "Welten" durchgespielt: die ungesehenen Karten
 * werden konsistent (Handgrößen + bekannte Farb-Freiheiten) an die Mitspieler verteilt
 * (Determinisierung), die Teams pro Welt aus den Kreuz-Damen abgeleitet, und der Rest des
 * Spiels mit einer schnellen Greedy-Politik ausgespielt. Gewählt wird die Karte mit den im
 * Schnitt meisten Augen für das eigene Team. Rollout-Zahl und Zeitbudget sind gedeckelt.
 *
 * Kein neuronales Netz (die FU-Arbeit von Obenaus zeigte dafür +0,04 Punkte/Spiel – nicht lohnend);
 * PIMC ist der tragende Ansatz. Reine Logik ohne DB; die Fakten sammelt
 * {@see \App\Application\Doppelkopf\Bot\ProfiStrategie}.
 */
final class ProfiHeuristik
{
    private const STECH_SCHWELLE = 10;

    public function __construct(private readonly StichGewinner $stichGewinner) {}

    /**
     * @param Karte[]            $erlaubte         Spielbare Karten (mind. eine)
     * @param Karte[]            $eigeneHand       Restliche Handkarten des Bots (inkl. $erlaubte)
     * @param array<int, Karte>  $offenerStich     Sitzplatz → Karte des laufenden Stichs (in Reihenfolge; leer = Bot spielt an)
     * @param array<int, int>    $restHandGroessen Sitzplatz (nur die 3 anderen) → Anzahl verbleibender Handkarten
     * @param Karte[]            $ungesehene       Karten, die bei den anderen liegen (Universum − gespielt − eigene Hand)
     * @param list<int>          $kreuzDameGespieltVon Sitze, die bereits eine Kreuz-Dame gespielt haben
     * @param array<int, ?Team>  $bekannteTeams    Sitzplatz → öffentlich bekanntes Team (für Solo/Hochzeit)
     */
    public function entscheide(
        array $erlaubte,
        array $eigeneHand,
        int $eigenerSitz,
        ?Team $eigenesTeam,
        array $offenerStich,
        array $restHandGroessen,
        array $ungesehene,
        SpielGedaechtnis $gedaechtnis,
        ?SpielVariante $variante,
        array $bekannteTeams,
        array $kreuzDameGespieltVon,
        TrumpfOrdnung $ordnung,
        bool $zweiteDulle,
        int $rollouts = 60,
        float $zeitbudgetSek = 0.8,
    ): Karte {
        $erlaubte = array_values($erlaubte);
        if ($erlaubte === []) {
            throw new \InvalidArgumentException('Es muss mindestens eine erlaubte Karte geben.');
        }
        if (count($erlaubte) === 1 || $eigenesTeam === null) {
            // Nichts zu entscheiden bzw. ohne bekanntes eigenes Team keine sinnvolle Bewertung.
            return $erlaubte[0];
        }

        $summe  = array_fill(0, count($erlaubte), 0.0);
        $anzahl = 0;
        $start  = microtime(true);

        for ($r = 0; $r < $rollouts; $r++) {
            if ($r > 0 && (microtime(true) - $start) >= $zeitbudgetSek) {
                break;
            }

            $welt = $this->determinisiere($ungesehene, $restHandGroessen, $gedaechtnis, $ordnung);
            if ($welt === null) {
                continue; // keine konsistente Verteilung gefunden – Welt überspringen
            }
            $teams = $this->teamsAbleiten($welt, $variante, $bekannteTeams, $eigenesTeam, $eigenerSitz, $kreuzDameGespieltVon);

            foreach ($erlaubte as $i => $kandidat) {
                $haende = $welt;
                $haende[$eigenerSitz] = $this->ohne($eigeneHand, $kandidat);

                $stich = $offenerStich;
                $stich[$eigenerSitz] = $kandidat;
                $amZug = ($eigenerSitz % 4) + 1;

                $augen = $this->ausspielen($haende, $stich, $amZug, $teams, $ordnung, $zweiteDulle);
                $summe[$i] += $augen[$eigenesTeam->value];
            }
            $anzahl++;
        }

        if ($anzahl === 0) {
            return $erlaubte[0];
        }

        $besterIndex = 0;
        foreach ($summe as $i => $wert) {
            if ($wert > $summe[$besterIndex]) {
                $besterIndex = $i;
            }
        }

        return $erlaubte[$besterIndex];
    }

    // ── Determinisierung ────────────────────────────────────────────────────

    /**
     * Verteilt die ungesehenen Karten auf die 3 anderen Sitze gemäß Handgrößen und
     * bekannten Farb-Freiheiten. Gibt null zurück, wenn keine konsistente Verteilung
     * gelingt (Aufrufer überspringt die Welt).
     *
     * @return array<int, Karte[]>|null Sitzplatz → zugeteilte Handkarten
     */
    private function determinisiere(array $ungesehene, array $restHandGroessen, SpielGedaechtnis $gedaechtnis, TrumpfOrdnung $ordnung): ?array
    {
        for ($versuch = 0; $versuch < 8; $versuch++) {
            $karten = $ungesehene;
            shuffle($karten);

            $haende = [];
            $bedarf = $restHandGroessen;
            foreach ($restHandGroessen as $sitz => $n) {
                $haende[$sitz] = [];
            }

            $erfolg = true;
            foreach ($karten as $karte) {
                $farbe = $ordnung->istTrumpf($karte) ? null : $ordnung->fehlfarbe($karte);

                $moeglich = [];
                foreach ($bedarf as $sitz => $n) {
                    if ($n > 0 && !$gedaechtnis->istFrei($sitz, $farbe)) {
                        $moeglich[] = $sitz;
                    }
                }
                if ($moeglich === []) {
                    $erfolg = false;
                    break;
                }

                $sitz = $moeglich[array_rand($moeglich)];
                $haende[$sitz][] = $karte;
                $bedarf[$sitz]--;
            }

            if ($erfolg) {
                return $haende;
            }
        }

        return null;
    }

    /**
     * Leitet die Teams für die Wertung ab. Im Normalspiel aus den Kreuz-Damen
     * (RE = hält/hielt eine Kreuz-Dame); bei Solo/Hochzeit aus den bekannten Teams.
     *
     * @param array<int, Karte[]> $welt
     * @param array<int, ?Team>   $bekannteTeams
     * @return array<int, Team> Sitzplatz → Team
     */
    private function teamsAbleiten(array $welt, ?SpielVariante $variante, array $bekannteTeams, Team $eigenesTeam, int $eigenerSitz, array $kreuzDameGespieltVon): array
    {
        $istNormal = $variante === null || $variante === SpielVariante::NORMALSPIEL;

        if (!$istNormal) {
            // Solo: alle bekannt. Hochzeit: unbekannte Sitze näherungsweise KONTRA.
            $teams = [];
            for ($sitz = 1; $sitz <= 4; $sitz++) {
                $teams[$sitz] = $bekannteTeams[$sitz] ?? Team::KONTRA;
            }
            return $teams;
        }

        $reSitze = array_fill_keys($kreuzDameGespieltVon, true);
        if ($eigenesTeam === Team::RE) {
            $reSitze[$eigenerSitz] = true;
        }
        foreach ($welt as $sitz => $hand) {
            foreach ($hand as $karte) {
                if ($karte->farbe === Kartenfarbe::KREUZ && $karte->wert === Kartenwert::DAME) {
                    $reSitze[$sitz] = true;
                }
            }
        }

        $teams = [];
        for ($sitz = 1; $sitz <= 4; $sitz++) {
            $teams[$sitz] = isset($reSitze[$sitz]) ? Team::RE : Team::KONTRA;
        }

        return $teams;
    }

    // ── Rollout ──────────────────────────────────────────────────────────────

    /**
     * Spielt eine Welt ab dem aktuellen Zustand mit Greedy-Politik zu Ende.
     *
     * @param array<int, Karte[]> $haende Sitzplatz → Handkarten (Karte des Bots bereits gespielt)
     * @param array<int, Karte>   $stich  Sitzplatz → Karte des laufenden Stichs
     * @param array<int, Team>    $teams  Sitzplatz → Team
     * @return array<string, int> Team-Wert ('RE'/'KONTRA') → Augen
     */
    private function ausspielen(array $haende, array $stich, int $amZug, array $teams, TrumpfOrdnung $ordnung, bool $zweiteDulle): array
    {
        $augen = [Team::RE->value => 0, Team::KONTRA->value => 0];

        while (true) {
            if (count($stich) === 4) {
                $gewinner = $this->stichGewinner->bestimme($stich, $ordnung, $zweiteDulle);
                $augen[$teams[$gewinner]->value] += array_sum(array_map(fn(Karte $k) => $k->augen(), $stich));
                $stich = [];
                $amZug = $gewinner;
                if ($this->alleLeer($haende)) {
                    break;
                }
                continue;
            }

            $hand = $haende[$amZug] ?? [];
            if ($hand === []) {
                break; // Sicherheitsnetz
            }

            $angespielt = $stich === [] ? null : array_values($stich)[0];
            $karte = $this->rolloutWahl($hand, $stich, $teams[$amZug], $teams, $angespielt, $ordnung);

            $haende[$amZug] = $this->ohne($hand, $karte);
            $stich[$amZug]  = $karte;
            $amZug = ($amZug % 4) + 1;
        }

        return $augen;
    }

    /** Schnelle Greedy-Kartenwahl im Rollout (perfekte Info in dieser Welt). */
    private function rolloutWahl(array $hand, array $stich, Team $eigenes, array $teams, ?Karte $angespielt, TrumpfOrdnung $ordnung): Karte
    {
        $legale = LegaleKarten::fuer($hand, $angespielt, $ordnung);

        if ($stich === []) {
            return $this->niedrigsteAugen($legale, $ordnung); // simpel anspielen
        }

        $gewinnerSitz  = array_key_first($stich);
        $gewinnerKarte = $stich[$gewinnerSitz];
        foreach ($stich as $sitz => $karte) {
            if ($this->schlaegt($karte, $gewinnerKarte, $angespielt, $ordnung)) {
                $gewinnerSitz  = $sitz;
                $gewinnerKarte = $karte;
            }
        }

        $partnerFuehrt = $teams[$gewinnerSitz] === $eigenes;
        $istLetzter    = count($stich) === 3;
        $stichAugen    = array_sum(array_map(fn(Karte $k) => $k->augen(), $stich));

        if ($partnerFuehrt) {
            return $istLetzter ? $this->hoechsteAugen($legale, $ordnung) : $this->niedrigsteAugen($legale, $ordnung);
        }

        $schlagende = array_values(array_filter(
            $legale,
            fn(Karte $k) => $this->schlaegt($k, $gewinnerKarte, $angespielt, $ordnung),
        ));
        if ($schlagende !== [] && ($istLetzter || $stichAugen >= self::STECH_SCHWELLE)) {
            return $this->billigsteSchlagende($schlagende, $ordnung);
        }

        return $this->niedrigsteAugen($legale, $ordnung);
    }

    // ── Helfer ────────────────────────────────────────────────────────────────

    /** @param Karte[] $karten */
    private function ohne(array $karten, Karte $entfernen): array
    {
        $raus = false;
        $rest = [];
        foreach ($karten as $k) {
            if (!$raus && $k->id() === $entfernen->id()) {
                $raus = true;
                continue;
            }
            $rest[] = $k;
        }

        return $rest;
    }

    private function alleLeer(array $haende): bool
    {
        foreach ($haende as $hand) {
            if ($hand !== []) {
                return false;
            }
        }

        return true;
    }

    private function schlaegt(Karte $kandidat, Karte $gewinner, ?Karte $angespielt, TrumpfOrdnung $ordnung): bool
    {
        $kandTrumpf = $ordnung->istTrumpf($kandidat);
        $gewTrumpf  = $ordnung->istTrumpf($gewinner);

        if ($kandTrumpf && !$gewTrumpf) {
            return true;
        }
        if (!$kandTrumpf && $gewTrumpf) {
            return false;
        }
        if ($kandTrumpf && $gewTrumpf) {
            return $ordnung->trumpfRang($kandidat) > $ordnung->trumpfRang($gewinner);
        }

        if ($angespielt === null || $ordnung->istTrumpf($angespielt)) {
            return false;
        }
        if ($ordnung->fehlfarbe($kandidat) !== $ordnung->fehlfarbe($angespielt)) {
            return false;
        }

        return $ordnung->fehlfarbenRang($kandidat) > $ordnung->fehlfarbenRang($gewinner);
    }

    private function billigsteSchlagende(array $schlagende, TrumpfOrdnung $ordnung): Karte
    {
        usort($schlagende, function (Karte $a, Karte $b) use ($ordnung) {
            $aT = $ordnung->istTrumpf($a);
            $bT = $ordnung->istTrumpf($b);
            if ($aT !== $bT) {
                return $aT <=> $bT;
            }
            $aR = $aT ? $ordnung->trumpfRang($a) : $ordnung->fehlfarbenRang($a);
            $bR = $bT ? $ordnung->trumpfRang($b) : $ordnung->fehlfarbenRang($b);
            return $aR <=> $bR;
        });

        return $schlagende[0];
    }

    private function hoechsteAugen(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, function (Karte $a, Karte $b) use ($ordnung) {
            if ($a->augen() !== $b->augen()) {
                return $b->augen() <=> $a->augen();
            }
            return $ordnung->istTrumpf($a) <=> $ordnung->istTrumpf($b);
        });

        return $karten[0];
    }

    private function niedrigsteAugen(array $karten, TrumpfOrdnung $ordnung): Karte
    {
        usort($karten, function (Karte $a, Karte $b) use ($ordnung) {
            if ($a->augen() !== $b->augen()) {
                return $a->augen() <=> $b->augen();
            }
            return $ordnung->istTrumpf($a) <=> $ordnung->istTrumpf($b);
        });

        return $karten[0];
    }
}
