<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\TischGesperrtException;
use App\Domain\Doppelkopf\Exception\TischZugangVerweigertException;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\ZugangsListenTyp;
use App\Enum\ZugangsModusTyp;
use App\Infrastructure\Mercure\LobbyMercurePublisher;
use App\Repository\SpielerZugangsListeRepository;
use App\Repository\TischSpielerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TischBeitrittsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TischSpielerRepository $tischSpielerRepo,
        private readonly SpielerZugangsListeRepository $zugangsListeRepo,
        private readonly LobbyMercurePublisher $mercurePublisher,
    ) {}

    public function erstelleTisch(User $ersteller, string $name, ZugangsModusTyp $zugangsmodus): Tisch
    {
        $tisch = new Tisch();
        $tisch->setName($name);
        $tisch->setErsteller($ersteller);
        $tisch->setZugangsmodus($zugangsmodus);

        $this->em->persist($tisch);

        $sitzplatz = new TischSpieler();
        $sitzplatz->setTisch($tisch);
        $sitzplatz->setUser($ersteller);
        $sitzplatz->setSitzplatz(1);

        $this->em->persist($sitzplatz);
        $this->em->flush();

        $this->mercurePublisher->lobbyAktualisiert();

        return $tisch;
    }

    public function beitreten(Tisch $tisch, User $user): TischSpieler
    {
        if ($tisch->isIstGesperrt()) {
            throw new TischGesperrtException();
        }

        if ($this->tischSpielerRepo->findByTischAndUser($tisch, $user) !== null) {
            throw TischZugangVerweigertException::weilBereitsAmTisch();
        }

        if ($tisch->getZugangsmodus() === ZugangsModusTyp::PRIVAT) {
            if (!$this->zugangsListeRepo->istAufWhitelist($tisch->getErsteller(), $user)) {
                throw TischZugangVerweigertException::weilNichtAufWhitelist();
            }
        }

        foreach ($tisch->getAktiveSpieler() as $aktiver) {
            if ($aktiver->getUser() === null) {
                continue;
            }
            if ($this->zugangsListeRepo->istAufBlacklist($aktiver->getUser(), $user)) {
                throw TischZugangVerweigertException::weilBlacklist();
            }
        }

        $tischSpieler = new TischSpieler();
        $tischSpieler->setTisch($tisch);
        $tischSpieler->setUser($user);

        $freierPlatz = $this->tischSpielerRepo->naechstesFreiesSitzplatz($tisch);
        if ($freierPlatz !== null) {
            $tischSpieler->setSitzplatz($freierPlatz);
        } else {
            $naechstePosition = $this->tischSpielerRepo->maxWarteschlangenPosition($tisch) + 1;
            $tischSpieler->setPositionInWarteschlange($naechstePosition);
        }

        $this->em->persist($tischSpieler);
        $this->em->flush();

        $this->mercurePublisher->lobbyAktualisiert();

        return $tischSpieler;
    }

    public function verlassen(Tisch $tisch, User $user): void
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null) {
            return;
        }

        $freigegebenerPlatz = $tischSpieler->getSitzplatz();

        $this->em->remove($tischSpieler);
        $this->em->flush();

        if ($freigegebenerPlatz !== null) {
            $this->warteschlangeNachruecken($tisch, $freigegebenerPlatz);
        }

        $this->mercurePublisher->lobbyAktualisiert();
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

        // Positionen der verbliebenen Warteschlange neu nummerieren
        foreach (array_slice($warteschlange, 1) as $i => $spieler) {
            $spieler->setPositionInWarteschlange($i + 1);
        }

        $this->em->flush();
    }
}
