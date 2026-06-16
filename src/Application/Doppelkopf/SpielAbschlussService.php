<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Service\PunkteZaehler;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Entity\GespielteKarte;
use App\Entity\Spiel;
use App\Enum\SpielStatus;
use App\Enum\Team;
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
        private readonly StichGewinner $stichGewinner,
        private readonly PunkteZaehler $punkteZaehler,
        private readonly SpielMercurePublisher $mercurePublisher,
    ) {}

    public function abschliessen(Spiel $spiel): void
    {
        $alleGespielten = $this->gespielteKarteRepo->findAlleGespieltenKarten($spiel);

        // Nach Stich gruppieren
        $sticheRoh = [];
        foreach ($alleGespielten as $gk) {
            $sticheRoh[$gk->getStichNr()][] = $gk;
        }

        // Team pro Sitzplatz
        $teamProSitzplatz = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            $teamProSitzplatz[$t->getSitzplatz()] = $t->getTeam();
        }

        $augenProTeam = [Team::RE->value => 0, Team::KONTRA->value => 0];

        foreach ($sticheRoh as $stichNr => $stichKartenRoh) {
            // Sortiere nach positionImStich → ergibt Spielreihenfolge
            usort($stichKartenRoh, fn(GespielteKarte $a, GespielteKarte $b)
                => $a->getPositionImStich() <=> $b->getPositionImStich());

            // Sitzplatz → Karte in korrekter Reihenfolge (als geordnetes Array)
            $kartenFuerGewinner = [];
            $stichAugen = 0;
            foreach ($stichKartenRoh as $gk) {
                $kartenFuerGewinner[$gk->getSitzplatz()] = $gk->alsKarte();
                $stichAugen += $gk->alsKarte()->augen();
            }

            $gewinnerSitzplatz = $this->stichGewinner->bestimme($kartenFuerGewinner);
            $team = $teamProSitzplatz[$gewinnerSitzplatz] ?? null;
            if ($team !== null) {
                $augenProTeam[$team->value] += $stichAugen;
            }
        }

        $ergebnis = $this->punkteZaehler->berechneErgebnis($augenProTeam);

        // Jede Ansage (Re, Contra, Keine-X) erhöht den Spielwert um 1
        $anzahlAnsagen = count($this->ansageRepo->findFuerSpiel($spiel));
        $ansageBonus   = $anzahlAnsagen;

        foreach ($spiel->getTeilnehmer() as $teilnehmer) {
            $team = $teilnehmer->getTeam();
            if ($team === null) continue;

            $basisDelta = $ergebnis['punkteDelta'][$team->value];
            // Bonus hat dasselbe Vorzeichen wie der Basisdelta (+Gewinner, -Verlierer)
            $delta = $basisDelta > 0
                ? $basisDelta + $ansageBonus
                : $basisDelta - $ansageBonus;

            $teilnehmer->setGewonnen($team === $ergebnis['sieger']);
            $teilnehmer->setPunkteDelta($delta);
        }

        $spiel->setStatus(SpielStatus::BEENDET);
        $spiel->setBeendetAm(new \DateTimeImmutable());

        $this->em->flush();

        $this->mercurePublisher->spielBeendet($spiel);
    }
}
