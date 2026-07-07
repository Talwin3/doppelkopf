<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Application\SystemEinstellungService;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\Service\WertungsRechner;
use App\Entity\GespielteKarte;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Enum\SpielStatus;
use App\Enum\Team;
use App\Infrastructure\Mercure\LobbyMercurePublisher;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielAnsageRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SpielAbschlussService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielAnsageRepository $ansageRepo,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly WertungsRechner $wertungsRechner,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly LobbyMercurePublisher $lobbyPublisher,
        private readonly SystemEinstellungService $einstellungService,
        private readonly TischProtokollService $protokoll,
    ) {}

    public function abschliessen(Spiel $spiel): void
    {
        $alleGespielten = $this->gespielteKarteRepo->findAlleGespieltenKarten($spiel);

        // Stiche in Spielreihenfolge aufbauen (je Stich die Karten in Ausspielreihenfolge).
        $sticheRoh = [];
        foreach ($alleGespielten as $gk) {
            $sticheRoh[$gk->getStichNr()][] = $gk;
        }
        ksort($sticheRoh);

        $stiche = [];
        foreach ($sticheRoh as $stichKartenRoh) {
            usort($stichKartenRoh, fn(GespielteKarte $a, GespielteKarte $b)
                => $a->getPositionImStich() <=> $b->getPositionImStich());

            $eintraege = [];
            foreach ($stichKartenRoh as $gk) {
                $eintraege[] = ['sitzplatz' => $gk->getSitzplatz(), 'karte' => $gk->alsKarte()];
            }
            $stiche[] = $eintraege;
        }

        $teamProSitzplatz = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->getTeam() !== null) {
                $teamProSitzplatz[$t->getSitzplatz()] = $t->getTeam();
            }
        }

        // Solist (lonely RE-Spieler) bei Soli ermitteln → zählt dreifach.
        $solistSitzplatz = null;
        if ($spiel->getVariante() !== null && str_starts_with($spiel->getVariante()->value, 'SOLO_')) {
            foreach ($spiel->getTeilnehmer() as $t) {
                if ($t->getTeam() === Team::RE) {
                    $solistSitzplatz = $t->getSitzplatz();
                    break;
                }
            }
        }

        $ansagen = [];
        foreach ($this->ansageRepo->findFuerSpiel($spiel) as $a) {
            $ansagen[] = ['sitzplatz' => $a->getSitzplatz(), 'typ' => $a->getAnsageTyp()];
        }

        $ordnung = $this->trumpfOrdnungFactory->fuerSpiel($spiel);
        $zweiteDulleSticht = (bool) ($spiel->getRegelEinstellungen()['zweite_dulle_sticht'] ?? false);

        $wertung = $this->wertungsRechner->berechne(
            $stiche,
            $teamProSitzplatz,
            $ordnung,
            $ansagen,
            $zweiteDulleSticht,
            $solistSitzplatz,
        );

        $spiel->setWertungDetails($wertung);

        $spielwert = $wertung['spielwert'];

        foreach ($spiel->getTeilnehmer() as $teilnehmer) {
            $team = $teilnehmer->getTeam();
            if ($team === null) continue;

            $istGewinner = $team->value === $wertung['sieger'];
            $delta = $istGewinner ? $spielwert : -$spielwert;

            if ($solistSitzplatz !== null && $teilnehmer->getSitzplatz() === $solistSitzplatz) {
                $delta *= 3;
            }

            $teilnehmer->setGewonnen($istGewinner);
            $teilnehmer->setPunkteDelta($delta);
        }

        $spiel->setStatus(SpielStatus::BEENDET);
        $spiel->setBeendetAm(new \DateTimeImmutable());
        $this->em->flush();

        $this->mercurePublisher->spielBeendet($spiel);

        $this->protokolliereErgebnis($spiel, $wertung);

        $this->nachSpielAbrechnungDurchfuehren($spiel->getTisch());
    }

    /**
     * Schreibt das Spielergebnis (Sieger, Augen, Spielwert, Gewinner) und etwaige
     * Sonderpunkte (Doppelkopf/Karlchen/Fuchs gefangen) ins Event-Log.
     *
     * @param array{augen: array<string, int>, sieger: string, positionen: list<array{label: string, team: string, punkte: int}>, spielwert: int} $wertung
     */
    private function protokolliereErgebnis(Spiel $spiel, array $wertung): void
    {
        $sieger    = $wertung['sieger'];
        $verlierer = $sieger === Team::RE->value ? Team::KONTRA->value : Team::RE->value;

        $augenSieger    = $wertung['augen'][$sieger] ?? 0;
        $augenVerlierer = $wertung['augen'][$verlierer] ?? 0;

        $siegerNamen = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->getTeam()?->value === $sieger) {
                $siegerNamen[] = $t->getAnzeigeName();
            }
        }

        $this->protokoll->ereignis($spiel->getTisch(), sprintf(
            'Spiel beendet: %s gewinnt %d:%d Augen (Spielwert %d) – %s.',
            $this->teamLabel($sieger),
            $augenSieger,
            $augenVerlierer,
            $wertung['spielwert'],
            $siegerNamen === [] ? '–' : implode(', ', $siegerNamen),
        ));

        // Sonderpunkte als eigene Zeile, der erzielenden Partei zugeordnet.
        $besonderheiten = [];
        foreach ($wertung['positionen'] as $p) {
            foreach (['Doppelkopf', 'Karlchen', 'Fuchs gefangen'] as $praefix) {
                if (str_starts_with($p['label'], $praefix)) {
                    $besonderheiten[] = sprintf('%s (%s)', $p['label'], $this->teamLabel($p['team']));
                    break;
                }
            }
        }
        if ($besonderheiten !== []) {
            $this->protokoll->ereignis(
                $spiel->getTisch(),
                'Besonderheiten: ' . implode(', ', $besonderheiten) . '.',
            );
        }
    }

    private function teamLabel(string $team): string
    {
        return $team === Team::RE->value ? 'Re' : 'Contra';
    }

    /**
     * Tisch-Lifecycle nach Spielende:
     * 1. Vorgemerkte Spieler entfernen
     * 2. Bots durch wartende Menschen ersetzen
     * 3. menschenloseSeitAm setzen/prüfen
     * 4. Auto-Start vorbereiten (naechsterSpielstartAm setzen)
     */
    private function nachSpielAbrechnungDurchfuehren(Tisch $tisch): void
    {
        $this->vorgemerkteEntfernen($tisch);
        $this->botsErsetzten($tisch);
        $this->rotationVorbereiten($tisch);
        $this->menschenlosePruefen($tisch);
        $this->autoStartVorbereiten($tisch);

        $this->em->flush();
        $this->lobbyPublisher->lobbyAktualisiert();
    }

    /**
     * Rückt die Vorhand für das nächste Spiel weiter, indem die Sitzplätze rotieren
     * (der bisherige Platz 2 wird neuer Platz 1). Weil das Frontend relativ zum
     * eigenen Sitzplatz zeichnet, bleibt die Tisch-Ansicht für jeden Spieler stabil –
     * es wechselt nur, wer herauskommt.
     *
     * Bei besetzter Warteschlange (>4 Mitspielende) rotiert zusätzlich der bisherige
     * Vorhand-Spieler (Platz 1) hinten in die Warteschlange und der erste Wartende
     * rückt auf Platz 4 nach – so kommt reihum jeder an den Tisch und heraus.
     *
     * Läuft nur bei genau 4 besetzten Plätzen; sonst startet ohnehin kein Spiel.
     */
    private function rotationVorbereiten(Tisch $tisch): void
    {
        $besetzt = [];
        foreach ($tisch->getAktiveSpieler() as $ts) {
            $besetzt[$ts->getSitzplatz()] = $ts;
        }
        if (count($besetzt) !== 4) {
            return;
        }
        ksort($besetzt);

        $warteschlange = $tisch->getWarteschlange()->toArray();
        usort($warteschlange, fn(TischSpieler $a, TischSpieler $b)
            => $a->getPositionInWarteschlange() <=> $b->getPositionInWarteschlange());

        if ($warteschlange !== []) {
            // Vorhand-Spieler raus in die Warteschlange, erster Wartender rein.
            $reinkommend = array_shift($warteschlange);
            $rausgehend  = $besetzt[1];
            $neueReihe   = [$besetzt[2], $besetzt[3], $besetzt[4], $reinkommend];
            $neueQueue   = array_merge($warteschlange, [$rausgehend]);

            $this->protokoll->ereignis($tisch, sprintf(
                '%s rotiert an den Tisch, %s setzt aus.',
                $reinkommend->getAnzeigeName(),
                $rausgehend->getAnzeigeName(),
            ));
        } else {
            // Reine Vorhand-Rotation unter den vier Sitzenden.
            $neueReihe = [$besetzt[2], $besetzt[3], $besetzt[4], $besetzt[1]];
            $neueQueue = [];
        }

        // Zweiphasig zuweisen: erst alle Sitze freimachen (null kollidiert nicht mit
        // dem UNIQUE(tisch, sitzplatz)-Constraint), dann neu setzen.
        foreach ($besetzt as $ts) {
            $ts->setSitzplatz(null);
        }
        $this->em->flush();

        foreach ($neueReihe as $i => $ts) {
            $ts->setSitzplatz($i + 1);
            $ts->setPositionInWarteschlange(null);
        }
        foreach ($neueQueue as $i => $ts) {
            $ts->setSitzplatz(null);
            $ts->setPositionInWarteschlange($i + 1);
        }
        $this->em->flush();
    }

    /** Entfernt alle aktiven Spieler, die moechteNachSpielVerlassen=true gesetzt haben. */
    private function vorgemerkteEntfernen(Tisch $tisch): void
    {
        $zuEntfernen = [];
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->isMoechteNachSpielVerlassen()) {
                $zuEntfernen[] = $ts;
            }
        }

        foreach ($zuEntfernen as $ts) {
            $freigesetzterPlatz = $ts->getSitzplatz();
            $this->em->remove($ts);
            $this->em->flush(); // flush nach jedem Entfernen damit Warteschlange korrekt zählt

            if ($freigesetzterPlatz !== null) {
                $this->warteschlangeNachruecken($tisch, $freigesetzterPlatz);
            }
        }
    }

    /**
     * Ersetzt Bots 1:1 durch wartende Menschen.
     * Bei 2 Bots + 1 Mensch in Queue → 1 Bot raus, 1 Mensch rein.
     */
    private function botsErsetzten(Tisch $tisch): void
    {
        $warteschlange = $tisch->getWarteschlange()->toArray();
        usort($warteschlange, fn(TischSpieler $a, TischSpieler $b)
            => $a->getPositionInWarteschlange() <=> $b->getPositionInWarteschlange());

        $menschenInQueue = array_filter($warteschlange, fn(TischSpieler $ts) => !$ts->isIstBot());

        foreach ($menschenInQueue as $mensch) {
            // Suche einen Bot-Sitzplatz
            $botZuErsetzen = null;
            foreach ($tisch->getAktiveSpieler() as $aktiver) {
                if ($aktiver->isIstBot()) {
                    $botZuErsetzen = $aktiver;
                    break;
                }
            }

            if ($botZuErsetzen === null) {
                break; // Keine Bots mehr am Tisch
            }

            $freigesetzterPlatz = $botZuErsetzen->getSitzplatz();
            $this->em->remove($botZuErsetzen);
            $this->em->flush();

            // Mensch aus Queue auf Bot-Platz setzen
            $mensch->setSitzplatz($freigesetzterPlatz);
            $mensch->setPositionInWarteschlange(null);
            $this->protokoll->ereignis(
                $tisch,
                sprintf('%s rückt aus der Warteschlange nach und übernimmt einen Bot-Platz.', $mensch->getAnzeigeName()),
            );

            // Queue-Positionen neu nummerieren
            $verbleibende = array_values(array_filter(
                $tisch->getWarteschlange()->toArray(),
                fn(TischSpieler $ts) => $ts !== $mensch,
            ));
            usort($verbleibende, fn(TischSpieler $a, TischSpieler $b)
                => $a->getPositionInWarteschlange() <=> $b->getPositionInWarteschlange());
            foreach ($verbleibende as $i => $ts) {
                $ts->setPositionInWarteschlange($i + 1);
            }

            $this->em->flush();
        }
    }

    /** Setzt menschenloseSeitAm wenn kein Mensch mehr am Tisch sitzt. */
    private function menschenlosePruefen(Tisch $tisch): void
    {
        if ($tisch->hatMenschAmTisch()) {
            if ($tisch->getMenschenloseSeitAm() !== null) {
                $tisch->setMenschenloseSeitAm(null);
            }
        } else {
            if ($tisch->getMenschenloseSeitAm() === null) {
                $tisch->setMenschenloseSeitAm(new \DateTimeImmutable());
            }
        }
    }

    /**
     * Setzt naechsterSpielstartAm wenn:
     * - autoStart aktiv
     * - 4 Spieler am Tisch
     * - noch kein Startzeit gesetzt
     */
    private function autoStartVorbereiten(Tisch $tisch): void
    {
        if (!$tisch->isAutoStart()) {
            return;
        }

        if ($tisch->anzahlAktiveSpieler() < 4) {
            return;
        }

        if (!$tisch->hatMenschAmTisch()) {
            return;
        }

        if ($tisch->getNaechsterSpielstartAm() !== null) {
            return;
        }

        $pauseSek = $this->einstellungService->getInt('pause_zwischen_spielen_sekunden');
        $tisch->setNaechsterSpielstartAm(
            (new \DateTimeImmutable())->modify("+{$pauseSek} seconds"),
        );
    }

    private function warteschlangeNachruecken(Tisch $tisch, int $freierPlatz): void
    {
        $warteschlange = $tisch->getWarteschlange()->toArray();
        if (empty($warteschlange)) {
            return;
        }

        usort($warteschlange, fn(TischSpieler $a, TischSpieler $b)
            => $a->getPositionInWarteschlange() <=> $b->getPositionInWarteschlange());

        $naechster = $warteschlange[0];
        $naechster->setSitzplatz($freierPlatz);
        $naechster->setPositionInWarteschlange(null);

        foreach (array_slice($warteschlange, 1) as $i => $spieler) {
            $spieler->setPositionInWarteschlange($i + 1);
        }

        $this->em->flush();
        $this->protokoll->ereignis(
            $tisch,
            sprintf('%s rückt aus der Warteschlange an den Tisch.', $naechster->getAnzeigeName()),
        );
    }
}
