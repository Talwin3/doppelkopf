<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\TischZugangVerweigertException;
use App\Domain\Doppelkopf\Service\KartenGeber;
use App\Domain\Doppelkopf\Service\TeamBestimmer;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\SpielRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SpielStartService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielRepository $spielRepo,
        private readonly KartenGeber $kartenGeber,
        private readonly TeamBestimmer $teamBestimmer,
        private readonly SpielMercurePublisher $mercurePublisher,
    ) {}

    /**
     * Startet ein neues Spiel an einem Tisch mit genau 4 Spielern.
     * Gibt das laufende Spiel zurück wenn bereits eines läuft.
     */
    public function starten(Tisch $tisch): Spiel
    {
        $laufendes = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
        if ($laufendes !== null) {
            return $laufendes;
        }

        if ($tisch->anzahlAktiveSpieler() < 4) {
            throw new \LogicException('Ein Spiel braucht genau 4 Spieler am Tisch.');
        }

        $haende = $this->kartenGeber->austeilen();

        // haende[0] → Sitzplatz 1, haende[1] → Sitzplatz 2, etc.
        $teamInfo = $this->teamBestimmer->bestimme([
            1 => $haende[0],
            2 => $haende[1],
            3 => $haende[2],
            4 => $haende[3],
        ]);

        $spiel = new Spiel();
        $spiel->setTisch($tisch);
        $spiel->setVariante($teamInfo['variante']);
        $spiel->setAktuellerSpielerSitzplatz(1);
        $spiel->setAktuellerStichNr(1);
        $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());

        $this->em->persist($spiel);

        $aktiveSpieler = $tisch->getAktiveSpieler()->toArray();
        usort($aktiveSpieler, fn($a, $b) => $a->getSitzplatz() <=> $b->getSitzplatz());

        foreach ($aktiveSpieler as $tischSpieler) {
            $platz = $tischSpieler->getSitzplatz();
            $hand  = $haende[$platz - 1];

            $teilnehmer = new SpielTeilnehmer();
            $teilnehmer->setSpiel($spiel);
            $teilnehmer->setUser($tischSpieler->getUser());
            $teilnehmer->setSitzplatz($platz);
            $teilnehmer->setStartkartenIds(array_map(fn($k) => $k->id(), $hand));
            $teilnehmer->setTeam($teamInfo['teams'][$platz]);
            $teilnehmer->setIstBot($tischSpieler->isIstBot());

            $this->em->persist($teilnehmer);
        }

        $this->em->flush();

        $this->mercurePublisher->spielGestartet($spiel);

        return $spiel;
    }
}
