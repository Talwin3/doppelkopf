<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Service\KartenGeber;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Enum\SpielStatus;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\SpielRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class SpielStartService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielRepository $spielRepo,
        private readonly KartenGeber $kartenGeber,
        private readonly SpielMercurePublisher $mercurePublisher,
    ) {}

    /**
     * Startet ein neues Spiel an einem Tisch mit genau 4 Spielern.
     * Gibt das laufende Spiel zurück wenn bereits eines läuft.
     */
    public function starten(Tisch $tisch): Spiel
    {
        // Konkurrierende Starts am selben Tisch serialisieren: ohne Lock können
        // zwei fast gleichzeitige Aufrufe (z. B. paralleler Seitenaufruf + Worker)
        // beide „kein laufendes Spiel" sehen und je ein Spiel anlegen.
        $this->em->beginTransaction();
        try {
            $this->em->lock($tisch, LockMode::PESSIMISTIC_WRITE);

            $laufendes = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
            if ($laufendes !== null) {
                $this->em->commit();
                return $laufendes;
            }

            if ($tisch->anzahlAktiveSpieler() < 4) {
                throw new \LogicException('Ein Spiel braucht genau 4 Spieler am Tisch.');
            }

            $haende = $this->kartenGeber->austeilen($tisch->getRegelEinstellungen());

            // Spiel startet in VORBEHALT-Phase; Teams werden durch SpielTypResolver nach Deklaration gesetzt
            $spiel = new Spiel();
            $spiel->setTisch($tisch);
            $spiel->setStatus(SpielStatus::VORBEHALT);
            $spiel->setAktuellerSpielerSitzplatz(1); // Sitzplatz 1 = Vorhand, deklariert zuerst
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
                $teilnehmer->setIstBot($tischSpieler->isIstBot());
                $teilnehmer->setBotName($tischSpieler->getBotName());
                $teilnehmer->setBotStaerke($tischSpieler->getBotStaerke());
                // team bleibt null bis SpielTypResolver läuft

                $this->em->persist($teilnehmer);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        }

        $this->mercurePublisher->kartenAusgeteilt($spiel);

        return $spiel;
    }
}
