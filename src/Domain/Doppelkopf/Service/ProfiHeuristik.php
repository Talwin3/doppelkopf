<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\AnsageAussichten;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\EndspielPolitik;
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
 * Spiels mit einer schnellen Greedy-Politik ausgespielt. Rollout-Zahl und Zeitbudget sind
 * gedeckelt.
 *
 * Bewertet wird eine Welt standardmäßig nach den **Augen** des eigenen Teams. Über
 * `$bewertePunkte` steht die Reward-Formel aus Sievers/Obenaus zur Verfügung:
 * `v = 500 · s + e` mit s = Turnierpunkte aus dem produktiven {@see WertungsRechner}
 * (121er-Schwelle, keine 90/60/30/schwarz, Fuchs/Karlchen/Doppelkopf, Ansage-Verdopplung)
 * und e = Augen als Feinabstimmung. Der Schwellen-Maßstab ist der sachlich richtigere –
 * **gemessen (2026-08-06) ist er bei r=60 aber schlechter** (gepaart gegen die Augen-Fassung:
 * −0,23 bzw. −0,12 Punkte je Spieler und Partie über je ~195 Blätter). Deshalb bleibt er
 * vorerst ausgeschaltet; die Formel steht als Messgegenstand bereit.
 *
 * Kein neuronales Netz (die FU-Arbeit von Obenaus zeigte dafür +0,04 Punkte/Spiel – nicht lohnend);
 * PIMC ist der tragende Ansatz. Reine Logik ohne DB; die Fakten sammelt
 * {@see \App\Application\Doppelkopf\Bot\ProfiStrategie}.
 */
final class ProfiHeuristik
{
    /**
     * Gewicht der Turnierpunkte gegenüber den Augen in `v = 500 · s + e`. Aus Sievers/Obenaus:
     * bei realistischen Werten (s ≈ −8…+8, e = 0…240) dominieren die Punkte, die Augen
     * entscheiden nur noch zwischen sonst punktgleichen Zügen.
     */
    private const PUNKT_GEWICHT = 500;

    /** Wie oft eine Welt neu gezogen wird, bis sie konsistent (und ggf. 2-gegen-2) ist. */
    private const WELT_VERSUCHE = 8;

    /**
     * Diagnose-Zähler der Determinisierung – wird nur beschrieben, nie gelesen; das Spiel
     * hängt nicht davon ab. Ausgewertet vom `app:bot:turnier`, siehe {@see WeltStatistik}.
     */
    public readonly WeltStatistik $statistik;

    /** Reichweite der exakten Endspielsuche – nur im Diagnosemodus gefüllt. */
    public readonly EndspielStatistik $endspielStatistik;

    /** Trennschärfe der Bewertungsformel – nur im Diagnosemodus gefüllt. */
    public readonly BewertungsStatistik $bewertungsStatistik;

    public function __construct(
        private readonly StichGewinner $stichGewinner,
        private readonly WertungsRechner $wertungsRechner,
        private readonly EndspielLoeser $endspielLoeser,
        private readonly RolloutPolitik $rolloutPolitik,
    ) {
        $this->statistik           = new WeltStatistik();
        $this->endspielStatistik   = new EndspielStatistik();
        $this->bewertungsStatistik = new BewertungsStatistik();
    }

    /**
     * @param Karte[]            $erlaubte         Spielbare Karten (mind. eine)
     * @param Karte[]            $eigeneHand       Restliche Handkarten des Bots (inkl. $erlaubte)
     * @param array<int, Karte>  $offenerStich     Sitzplatz → Karte des laufenden Stichs (in Reihenfolge; leer = Bot spielt an)
     * @param array<int, int>    $restHandGroessen Sitzplatz (nur die 3 anderen) → Anzahl verbleibender Handkarten
     * @param Karte[]            $ungesehene       Karten, die bei den anderen liegen (Universum − gespielt − eigene Hand)
     * @param list<int>          $kreuzDameGespieltVon Sitze, die bereits eine Kreuz-Dame gespielt haben
     * @param array<int, ?Team>  $bekannteTeams    Sitzplatz → öffentlich bekanntes Team (für Solo/Hochzeit)
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $bisherigeStiche Abgeschlossene Stiche dieses Spiels –
     *        die Wertungsschwellen gelten fürs ganze Spiel, nicht erst ab der aktuellen Stellung
     * @param list<array{sitzplatz: int, typ: AnsageTyp}>     $ansagen         Bisherige Ansagen (verdoppeln den Spielwert)
     * @param int|null           $solistSitzplatz  gesetzt bei Solo/Hochzeit-als-Solo
     * @param bool               $bewertePunkte    true = `v = 500·s + e` statt reiner Ø-Augen
     * @param bool               $nurZweiGegenZwei true = Welten mit stiller Hochzeit (1 gegen 3) verwerfen.
     *        Solche Welten sind im angemeldeten Normalspiel praktisch ausgeschlossen (Bots melden die
     *        Hochzeit immer an), fielen ohne diese Prüfung aber in ~30 % der Ziehungen an, sobald der
     *        Bot selbst keine Kreuz-Dame hält. Messung 2026-08-07: +0,19 Punkte je Spieler und Partie.
     * @param bool               $bewertungsDiagnose true = beide Maßstäbe je Welt mitschreiben und in
     *        die {@see BewertungsStatistik} geben. Ändert die Kartenwahl nicht, kostet aber eine
     *        zusätzliche Abrechnung je Welt und Kandidat.
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
        array $bisherigeStiche = [],
        array $ansagen = [],
        ?int $solistSitzplatz = null,
        bool $bewertePunkte = false,
        bool $nurZweiGegenZwei = true,
        int $rollouts = 60,
        float $zeitbudgetSek = 0.8,
        int $endspielAbRestkarten = 0,
        bool $endspielDiagnose = false,
        EndspielPolitik $endspielPolitik = EndspielPolitik::MINIMAX,
        bool $bewertungsDiagnose = false,
        bool $teamNebenbedingung = true,
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

        // Diagnose: dieselbe Stellung zusätzlich greedy bewerten, um die beiden Wahlen zu
        // vergleichen. Kostet die doppelte Rechenzeit und ist deshalb per Default aus.
        // Läuft eine andere Politik als Minimax, wird zusätzlich diese gerechnet – die Frage
        // „ändert das Gegnermodell überhaupt die Karte?" ist die, an der ein Stärkelauf hängt.
        $diagnoseMinimax = $endspielDiagnose && $endspielPolitik !== EndspielPolitik::MINIMAX;
        $summeGreedy     = $endspielDiagnose ? $summe : [];
        $summeMinimax    = $diagnoseMinimax ? $summe : [];
        $endspielGenutzt = false;

        // Bewertungs-Diagnose: beide Maßstäbe je Welt und Kandidat mitschreiben, ohne die
        // Wahl anzufassen. Aus den Rohwerten fällt der ganze Gewichts-Sweep ab und der
        // Standardfehler des Abstands zwischen Platz 1 und 2 (siehe BewertungsStatistik).
        $diagPunkte = [];
        $diagAugen  = [];

        [$mussKreuzDame, $keineKreuzDame] = $this->teamNebenbedingungen(
            $bekannteTeams,
            $restHandGroessen,
            $kreuzDameGespieltVon,
            $variante,
        );

        for ($r = 0; $r < $rollouts; $r++) {
            if ($r > 0 && (microtime(true) - $start) >= $zeitbudgetSek) {
                break;
            }

            $welt  = null;
            $teams = null;
            $this->statistik->weltAnlaeufe++;
            for ($versuch = 0; $versuch < self::WELT_VERSUCHE; $versuch++) {
                $kandidatWelt = $this->determinisiere(
                    $ungesehene,
                    $restHandGroessen,
                    $gedaechtnis,
                    $ordnung,
                    $mussKreuzDame,
                    $keineKreuzDame,
                    $teamNebenbedingung,
                );
                if ($kandidatWelt === null) {
                    continue; // keine konsistente Verteilung gefunden
                }

                $kandidatTeams = $this->teamsAbleiten($kandidatWelt, $variante, $bekannteTeams, $eigenesTeam, $eigenerSitz, $kreuzDameGespieltVon);
                if ($nurZweiGegenZwei && !$this->istZweiGegenZwei($kandidatTeams, $variante)) {
                    $this->statistik->verworfenEinGegenDrei++;
                    continue; // stille Hochzeit – in einem angemeldeten Normalspiel praktisch ausgeschlossen
                }

                $welt  = $kandidatWelt;
                $teams = $kandidatTeams;
                break;
            }
            if ($welt === null || $teams === null) {
                $this->statistik->weltAusfaelle++;
                continue; // Welt überspringen
            }

            foreach ($erlaubte as $i => $kandidat) {
                $haende = $welt;
                $haende[$eigenerSitz] = $this->ohne($eigeneHand, $kandidat);

                $stich = $offenerStich;
                $stich[$eigenerSitz] = $kandidat;
                $amZug = ($eigenerSitz % 4) + 1;

                if ($this->endspielReif($haende, $endspielAbRestkarten, $bewertePunkte)) {
                    // Perfekte Information in dieser Welt – der Rest ist ausrechenbar.
                    $endspielGenutzt = true;
                    $summe[$i] += $this->endspielLoeser->augen($haende, $stich, $amZug, $teams, $eigenesTeam, $ordnung, $zweiteDulle, $endspielPolitik, $eigenerSitz);

                    if ($endspielDiagnose) {
                        $greedyStiche = $this->ausspielen($haende, $stich, $amZug, $teams, $ordnung, $zweiteDulle);
                        $summeGreedy[$i] += $this->augenFuer($greedyStiche, $teams, $eigenesTeam, $ordnung, $zweiteDulle);
                    }
                    if ($diagnoseMinimax) {
                        $summeMinimax[$i] += $this->endspielLoeser->augen($haende, $stich, $amZug, $teams, $eigenesTeam, $ordnung, $zweiteDulle);
                    }
                    continue;
                }

                $stiche = $this->ausspielen($haende, $stich, $amZug, $teams, $ordnung, $zweiteDulle);
                $wert   = $bewertePunkte
                    ? $this->bewerteWelt(
                        array_merge($bisherigeStiche, $stiche),
                        $teams,
                        $eigenesTeam,
                        $ordnung,
                        $ansagen,
                        $zweiteDulle,
                        $solistSitzplatz,
                    )
                    : $this->augenFuer($stiche, $teams, $eigenesTeam, $ordnung, $zweiteDulle);
                $summe[$i] += $wert;
                if ($endspielDiagnose) {
                    $summeGreedy[$i] += $wert; // außerhalb des Endspiels sind alle Wege identisch
                }
                if ($diagnoseMinimax) {
                    $summeMinimax[$i] += $wert;
                }
                if ($bewertungsDiagnose) {
                    // Bewusst zusätzlich gerechnet statt aus $wert abgeleitet: so bleibt die
                    // Wahl bit-identisch zum Normalbetrieb, egal welcher Maßstab gerade läuft.
                    $wertung = $this->wertungsRechner->berechne(
                        array_merge($bisherigeStiche, $stiche),
                        $teams,
                        $ordnung,
                        $ansagen,
                        $zweiteDulle,
                        $solistSitzplatz,
                    );
                    $diagPunkte[$i][] = $this->signierterWert($wertung, $eigenesTeam);
                    $diagAugen[$i][]  = (float) $wertung['augen'][$eigenesTeam->value];
                }
            }
            $anzahl++;
        }

        if ($anzahl === 0) {
            return $erlaubte[0];
        }

        $besterIndex = $this->bestenIndex($summe);

        if ($endspielDiagnose) {
            $this->endspielStatistik->zuege++;
            if ($endspielGenutzt) {
                $this->endspielStatistik->zuegeMitEndspiel++;
                if ($this->bestenIndex($summeGreedy) !== $besterIndex) {
                    $this->endspielStatistik->zuegeWahlGeaendert++;
                }
                if ($diagnoseMinimax && $this->bestenIndex($summeMinimax) !== $besterIndex) {
                    $this->endspielStatistik->zuegeAbweichendVonMinimax++;
                }
            }
        }

        // Züge, in denen die exakte Suche gegriffen hat, tragen keine Welt-Rohwerte und
        // bleiben deshalb außen vor – der Löser liefert nur den Wert, nicht beide Maßstäbe.
        if ($bewertungsDiagnose && !$endspielGenutzt && $diagPunkte !== []) {
            $this->bewertungsStatistik->erfasse(array_values($diagPunkte), array_values($diagAugen));
        }

        return $erlaubte[$besterIndex];
    }

    /**
     * Index des höchsten Werts – bei Gleichstand der erste, damit beide Bewertungswege im
     * Diagnosemodus nach derselben Regel wählen und der Vergleich fair bleibt.
     *
     * @param array<int, float> $werte
     */
    private function bestenIndex(array $werte): int
    {
        $bester = 0;
        foreach ($werte as $i => $wert) {
            if ($wert > $werte[$bester]) {
                $bester = $i;
            }
        }

        return $bester;
    }

    /**
     * Schätzt die Aussichten der eigenen Partei – Grundlage der Ansage-Entscheidung
     * ({@see \App\Domain\Doppelkopf\ValueObject\AnsageRollout}).
     *
     * Zieht dieselben Welten wie {@see self::entscheide} und spielt jede von der aktuellen
     * Stellung aus zu Ende – diesmal ohne Kandidat-Karte, denn gefragt ist nicht „welche
     * Karte", sondern „wie steht die Partei". Aus jeder fertigen Welt fällt ab, ob die
     * Partei gewinnt und wie viele Augen die Gegenpartei bekommt; daraus die
     * Wahrscheinlichkeiten. Zusätzlich wird jede Welt einmal je Kandidat-Ansage durch den
     * {@see WertungsRechner} gerechnet, was den Erwartungswert der Ansage liefert.
     *
     * @param AnsageTyp[] $kandidaten Ansagen, die gerade zur Debatte stehen (regelkonform
     *        vorgefiltert – die Engine prüft weder Fristen noch Voraussetzungsketten)
     *
     * @see self::entscheide für die Bedeutung der übrigen Parameter
     */
    public function aussichten(
        array $kandidaten,
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
        array $bisherigeStiche = [],
        array $ansagen = [],
        ?int $solistSitzplatz = null,
        bool $nurZweiGegenZwei = true,
        int $rollouts = 60,
        float $zeitbudgetSek = 0.8,
        bool $teamNebenbedingung = true,
    ): AnsageAussichten {
        if ($kandidaten === [] || $eigenesTeam === null || $eigeneHand === []) {
            return new AnsageAussichten(0);
        }

        [$mussKreuzDame, $keineKreuzDame] = $this->teamNebenbedingungen(
            $bekannteTeams,
            $restHandGroessen,
            $kreuzDameGespieltVon,
            $variante,
        );

        $gegenTeam = $eigenesTeam === Team::RE ? Team::KONTRA : Team::RE;

        $summeOhne = 0.0;
        $summeMit  = array_fill_keys(array_map(static fn(AnsageTyp $t) => $t->value, $kandidaten), 0.0);
        $haelt     = array_fill_keys(array_keys($summeMit), 0);
        $anzahl    = 0;
        $start     = microtime(true);

        for ($r = 0; $r < $rollouts; $r++) {
            if ($r > 0 && (microtime(true) - $start) >= $zeitbudgetSek) {
                break;
            }

            $welt  = null;
            $teams = null;
            $this->statistik->weltAnlaeufe++;
            for ($versuch = 0; $versuch < self::WELT_VERSUCHE; $versuch++) {
                $kandidatWelt = $this->determinisiere(
                    $ungesehene,
                    $restHandGroessen,
                    $gedaechtnis,
                    $ordnung,
                    $mussKreuzDame,
                    $keineKreuzDame,
                    $teamNebenbedingung,
                );
                if ($kandidatWelt === null) {
                    continue;
                }

                $kandidatTeams = $this->teamsAbleiten($kandidatWelt, $variante, $bekannteTeams, $eigenesTeam, $eigenerSitz, $kreuzDameGespieltVon);
                if ($nurZweiGegenZwei && !$this->istZweiGegenZwei($kandidatTeams, $variante)) {
                    $this->statistik->verworfenEinGegenDrei++;
                    continue;
                }

                $welt  = $kandidatWelt;
                $teams = $kandidatTeams;
                break;
            }
            if ($welt === null || $teams === null) {
                $this->statistik->weltAusfaelle++;
                continue;
            }

            // Die eigene Hand gehört im Rollout dazu – gespielt wird ab dem eigenen Sitz.
            $welt[$eigenerSitz] = $eigeneHand;
            $stiche = array_merge(
                $bisherigeStiche,
                $this->ausspielen($welt, $offenerStich, $eigenerSitz, $teams, $ordnung, $zweiteDulle),
            );

            $ohne = $this->wertungsRechner->berechne($stiche, $teams, $ordnung, $ansagen, $zweiteDulle, $solistSitzplatz);
            $summeOhne += $this->signierterWert($ohne, $eigenesTeam);

            $gegenAugen  = $ohne['augen'][$gegenTeam->value] ?? 0;
            $gegenStiche = $ohne['stiche'][$gegenTeam->value] ?? 0;

            foreach ($kandidaten as $typ) {
                $mitAnsagen = array_merge($ansagen, [['sitzplatz' => $eigenerSitz, 'typ' => $typ]]);
                $mit = $this->wertungsRechner->berechne($stiche, $teams, $ordnung, $mitAnsagen, $zweiteDulle, $solistSitzplatz);
                $summeMit[$typ->value] += $this->signierterWert($mit, $eigenesTeam);

                if ($this->ansageHaelt($typ, $ohne['sieger'] ?? null, $eigenesTeam, $gegenAugen, $gegenStiche)) {
                    $haelt[$typ->value]++;
                }
            }

            $anzahl++;
        }

        if ($anzahl === 0) {
            return new AnsageAussichten(0);
        }

        return new AnsageAussichten(
            welten: $anzahl,
            evOhne: $summeOhne / $anzahl,
            evMit: array_map(static fn(float $s) => $s / $anzahl, $summeMit),
            haelt: array_map(static fn(int $n) => $n / $anzahl, $haelt),
        );
    }

    /**
     * Geht diese Ansage in der ausgespielten Welt auf? Re/Contra verlangen den Sieg, jede
     * Absage das Unterschreiten ihrer Grenze durch die Gegenpartei. Gerechnet wird gegen die
     * Wertung **ohne** die Ansage – sonst wäre die Ansage ihre eigene Voraussetzung: eine
     * verfehlte Absage macht die eigene Partei zum Verlierer, und `sieger` wäre dann immer
     * negativ, sobald man sie hypothetisch hinzunimmt.
     */
    private function ansageHaelt(AnsageTyp $typ, ?string $sieger, Team $eigenesTeam, int $gegenAugen, int $gegenStiche): bool
    {
        return match ($typ) {
            AnsageTyp::RE, AnsageTyp::CONTRA => $sieger === $eigenesTeam->value,
            AnsageTyp::KEINE_NEUN            => $gegenAugen < 90,
            AnsageTyp::KEINE_SECHS           => $gegenAugen < 60,
            AnsageTyp::KEINE_DREI            => $gegenAugen < 30,
            AnsageTyp::SCHWARZ               => $gegenStiche === 0,
        };
    }

    /** @param array{punkteEmpfaenger: string, spielwert: int} $wertung */
    private function signierterWert(array $wertung, Team $eigenesTeam): float
    {
        return $wertung['punkteEmpfaenger'] === $eigenesTeam->value
            ? (float) $wertung['spielwert']
            : -(float) $wertung['spielwert'];
    }

    // ── Determinisierung ────────────────────────────────────────────────────

    /**
     * Verteilt die ungesehenen Karten auf die 3 anderen Sitze gemäß Handgrößen und
     * bekannten Farb-Freiheiten. Gibt null zurück, wenn keine konsistente Verteilung
     * gelingt (Aufrufer überspringt die Welt).
     *
     * Mit `$erzwingeTeams` kommt die **Kreuz-Damen-Nebenbedingung** dazu: wer im Normalspiel
     * „Re" angesagt hat, hält eine Kreuz-Dame; wer „Contra" angesagt hat, hält keine. Das ist
     * keine Schätzung, sondern eine Regelfolge – und die Teams sind über
     * {@see TeamSichtbarkeit} ohnehin öffentlich, es wird also nichts ausgenutzt, was ein
     * Mensch am Tisch nicht auch wüsste. Ohne den Schalter wird der Widerspruch nur gezählt.
     *
     * @param list<int> $mussKreuzDame  Sitze, die zwingend eine der ungesehenen Kreuz-Damen halten
     * @param list<int> $keineKreuzDame Sitze, die sicher keine halten
     *
     * @return array<int, Karte[]>|null Sitzplatz → zugeteilte Handkarten
     */
    private function determinisiere(
        array $ungesehene,
        array $restHandGroessen,
        SpielGedaechtnis $gedaechtnis,
        TrumpfOrdnung $ordnung,
        array $mussKreuzDame = [],
        array $keineKreuzDame = [],
        bool $erzwingeTeams = false,
    ): ?array {
        $this->statistik->ziehungen++;
        $hatFakten = $mussKreuzDame !== [] || $keineKreuzDame !== [];

        for ($versuch = 0; $versuch < 8; $versuch++) {
            $karten = $ungesehene;
            shuffle($karten);

            $haende = [];
            $bedarf = $restHandGroessen;
            foreach ($restHandGroessen as $sitz => $n) {
                $haende[$sitz] = [];
            }

            // Die Pflicht-Kreuz-Damen zuerst – die am stärksten eingeschränkten Karten
            // zuerst zu setzen ist derselbe Gedanke wie in Sievers' CAP-Algorithmus.
            $erfolg = true;
            if ($erzwingeTeams && $mussKreuzDame !== []) {
                $sitze = $mussKreuzDame;
                shuffle($sitze);
                foreach ($sitze as $sitz) {
                    $index = null;
                    foreach ($karten as $i => $karte) {
                        if ($this->istKreuzDame($karte)) {
                            $index = $i;
                            break;
                        }
                    }
                    if ($index === null || ($bedarf[$sitz] ?? 0) <= 0 || $gedaechtnis->istFrei($sitz, null)) {
                        $erfolg = false;
                        break;
                    }
                    $haende[$sitz][] = $karten[$index];
                    unset($karten[$index]);
                    $karten = array_values($karten);
                    $bedarf[$sitz]--;
                }
            }

            if ($erfolg) {
                foreach ($karten as $karte) {
                    $farbe        = $ordnung->istTrumpf($karte) ? null : $ordnung->fehlfarbe($karte);
                    $istKreuzDame = $erzwingeTeams && $this->istKreuzDame($karte);

                    $moeglich = [];
                    foreach ($bedarf as $sitz => $n) {
                        if ($n <= 0 || $gedaechtnis->istFrei($sitz, $farbe)) {
                            continue;
                        }
                        if ($istKreuzDame && in_array($sitz, $keineKreuzDame, true)) {
                            continue; // dieser Sitz hat Contra angesagt – er kann keine halten
                        }
                        $moeglich[] = $sitz;
                    }
                    if ($moeglich === []) {
                        $erfolg = false;
                        break;
                    }

                    $sitz = $moeglich[array_rand($moeglich)];
                    $haende[$sitz][] = $karte;
                    $bedarf[$sitz]--;
                }
            }

            if ($erfolg) {
                if ($hatFakten) {
                    $this->statistik->weltenMitTeamFakten++;
                    if ($this->widersprichtTeams($haende, $mussKreuzDame, $keineKreuzDame)) {
                        $this->statistik->weltenMitTeamWiderspruch++;
                    }
                }

                return $haende;
            }
        }

        $this->statistik->ziehungenOhneErgebnis++;

        return null;
    }

    /**
     * Widerspricht diese Welt einem bekannten Team? Reine Diagnose – misst, wie viel die
     * Nebenbedingung überhaupt bewegen kann, bevor sie scharf geschaltet wird.
     *
     * @param array<int, Karte[]> $haende
     * @param list<int>           $mussKreuzDame
     * @param list<int>           $keineKreuzDame
     */
    private function widersprichtTeams(array $haende, array $mussKreuzDame, array $keineKreuzDame): bool
    {
        foreach ($mussKreuzDame as $sitz) {
            if (!$this->haeltKreuzDame($haende[$sitz] ?? [])) {
                return true;
            }
        }
        foreach ($keineKreuzDame as $sitz) {
            if ($this->haeltKreuzDame($haende[$sitz] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /** @param Karte[] $hand */
    private function haeltKreuzDame(array $hand): bool
    {
        foreach ($hand as $karte) {
            if ($this->istKreuzDame($karte)) {
                return true;
            }
        }

        return false;
    }

    private function istKreuzDame(Karte $karte): bool
    {
        return $karte->farbe === Kartenfarbe::KREUZ && $karte->wert === Kartenwert::DAME;
    }

    /**
     * Leitet aus den öffentlich bekannten Teams die harten Nebenbedingungen für die
     * Determinisierung ab – nur im Normalspiel, wo „RE" gleichbedeutend mit „hält eine
     * Kreuz-Dame" ist. Sitze, die ihre Kreuz-Dame schon gespielt haben, sind erfüllt und
     * brauchen keine weitere.
     *
     * @param array<int, ?Team> $bekannteTeams
     * @param array<int, int>   $restHandGroessen nur die drei fremden Sitze
     * @param list<int>         $kreuzDameGespieltVon
     *
     * @return array{0: list<int>, 1: list<int>} [muss eine halten, hält sicher keine]
     */
    private function teamNebenbedingungen(
        array $bekannteTeams,
        array $restHandGroessen,
        array $kreuzDameGespieltVon,
        ?SpielVariante $variante,
    ): array {
        if ($variante !== null && $variante !== SpielVariante::NORMALSPIEL) {
            return [[], []]; // bei Solo/Hochzeit sagt die Kreuz-Dame nichts über die Partei
        }

        $muss  = [];
        $keine = [];
        foreach (array_keys($restHandGroessen) as $sitz) {
            $team = $bekannteTeams[$sitz] ?? null;
            if ($team === Team::RE) {
                if (!in_array($sitz, $kreuzDameGespieltVon, true)) {
                    $muss[] = $sitz;
                }
            } elseif ($team === Team::KONTRA) {
                $keine[] = $sitz;
            }
        }

        return [$muss, $keine];
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

    /**
     * Steht in dieser Welt eine reguläre 2-gegen-2-Partie?
     *
     * Hält der Bot selbst keine Kreuz-Dame, landen beide ungesehenen in rund 30 % der
     * Determinisierungen beim selben Sitz – die Welt ist dann eine **stille Hochzeit**
     * (einer gegen drei). In einem Spiel, das als Normalspiel läuft, ist das aber der
     * seltene Ausnahmefall: wer beide Kreuz-Damen hält, meldet üblicherweise Hochzeit an
     * (Bots immer). Solche Welten so häufig zu ziehen, verzerrt jede Bewertung, die an der
     * Teamform hängt – 121er-Schwelle, „Gegen die Alten", keine 90/60/30.
     *
     * @param array<int, Team> $teams
     */
    private function istZweiGegenZwei(array $teams, ?SpielVariante $variante): bool
    {
        if ($variante !== null && $variante !== SpielVariante::NORMALSPIEL) {
            return true; // Solo/Hochzeit sind gar nicht 2-gegen-2 gemeint
        }

        $re = 0;
        foreach ($teams as $team) {
            if ($team === Team::RE) {
                $re++;
            }
        }

        return $re === 2;
    }

    // ── Rollout ──────────────────────────────────────────────────────────────

    /**
     * Spielt eine Welt ab dem aktuellen Zustand mit Greedy-Politik zu Ende.
     *
     * Geliefert werden die dabei abgeschlossenen Stiche – in genau der Form, die der
     * {@see WertungsRechner} erwartet, damit die Bewertung die volle Abrechnung nutzen kann.
     *
     * @param array<int, Karte[]> $haende Sitzplatz → Handkarten (Karte des Bots bereits gespielt)
     * @param array<int, Karte>   $stich  Sitzplatz → Karte des laufenden Stichs
     * @param array<int, Team>    $teams  Sitzplatz → Team
     * @return list<list<array{sitzplatz: int, karte: Karte}>> Stiche in Spielreihenfolge
     */
    private function ausspielen(array $haende, array $stich, int $amZug, array $teams, TrumpfOrdnung $ordnung, bool $zweiteDulle): array
    {
        $stiche = [];

        while (true) {
            if (count($stich) === 4) {
                $gewinner = $this->stichGewinner->bestimme($stich, $ordnung, $zweiteDulle);
                $eintraege = [];
                foreach ($stich as $sitz => $karte) {
                    $eintraege[] = ['sitzplatz' => $sitz, 'karte' => $karte];
                }
                $stiche[] = $eintraege;
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
            $karte = $this->rolloutPolitik->waehle($hand, $stich, $teams[$amZug], $teams, $angespielt, $ordnung);

            $haende[$amZug] = $this->ohne($hand, $karte);
            $stich[$amZug]  = $karte;
            $amZug = ($amZug % 4) + 1;
        }

        return $stiche;
    }

    /**
     * Wert einer zu Ende gespielten Welt aus Sicht des eigenen Teams: `v = 500 · s + e`.
     *
     * `s` kommt aus dem produktiven {@see WertungsRechner} und enthält damit alles, was
     * Doppelkopf ausmacht: die 121-Schwelle, keine 90/60/30/schwarz, Fuchs, Karlchen,
     * Doppelköpfe und die Verdopplung durch Ansagen (inklusive der Verpflichtung, eine
     * eigene Absage auch zu erfüllen). Der Solisten-Faktor 3 bleibt außen vor – er
     * skaliert alle Kandidaten gleich und würde nur das Gewicht der Augen verschieben.
     *
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $stiche  ganzes Spiel, nicht nur der Rollout
     * @param array<int, Team>                                $teams
     * @param list<array{sitzplatz: int, typ: AnsageTyp}>     $ansagen
     */
    private function bewerteWelt(
        array $stiche,
        array $teams,
        Team $eigenesTeam,
        TrumpfOrdnung $ordnung,
        array $ansagen,
        bool $zweiteDulle,
        ?int $solistSitzplatz,
    ): float {
        $wertung = $this->wertungsRechner->berechne($stiche, $teams, $ordnung, $ansagen, $zweiteDulle, $solistSitzplatz);

        return self::PUNKT_GEWICHT * $this->signierterWert($wertung, $eigenesTeam)
            + $wertung['augen'][$eigenesTeam->value];
    }

    /**
     * Augen, die eine Partei in den übergebenen Stichen holt.
     *
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $stiche
     * @param array<int, Team>                                $teams
     */
    private function augenFuer(array $stiche, array $teams, Team $team, TrumpfOrdnung $ordnung, bool $zweiteDulle): int
    {
        $augen = 0;

        foreach ($stiche as $stich) {
            $karten = [];
            $summe  = 0;
            foreach ($stich as $eintrag) {
                $karten[$eintrag['sitzplatz']] = $eintrag['karte'];
                $summe += $eintrag['karte']->augen();
            }

            $gewinner = $this->stichGewinner->bestimme($karten, $ordnung, $zweiteDulle);
            if (($teams[$gewinner] ?? null) === $team) {
                $augen += $summe;
            }
        }

        return $augen;
    }

    /**
     * Ist die Stellung klein genug, um sie exakt auszurechnen statt sie zu schätzen?
     *
     * Maßgeblich ist die größte verbliebene Hand – die Suche läuft bis zum Spielende, ihre
     * Tiefe hängt also am längsten Sitz. Ab dieser Grenze ersetzt der {@see EndspielLoeser}
     * den Greedy-Rollout.
     *
     * ⚠️ Nur im Augen-Maßstab: der Löser liefert einen Wert, keine Stichliste, und die
     * (per Default abgeschaltete) Punkte-Bewertung braucht die einzelnen Stiche.
     *
     * @param array<int, Karte[]> $haende
     */
    private function endspielReif(array $haende, int $abRestkarten, bool $bewertePunkte): bool
    {
        if ($abRestkarten <= 0 || $bewertePunkte) {
            return false;
        }

        foreach ($haende as $hand) {
            if (count($hand) > $abRestkarten) {
                return false;
            }
        }

        return true;
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

}
