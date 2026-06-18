<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\TischGesperrtException;
use App\Domain\Doppelkopf\Exception\TischZugangVerweigertException;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\ZugangsListenTyp;
use App\Enum\ZugangsModusTyp;
use App\Infrastructure\Mercure\LobbyMercurePublisher;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\SpielerZugangsListeRepository;
use App\Repository\SpielRepository;
use App\Repository\TischSpielerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TischBeitrittsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TischSpielerRepository $tischSpielerRepo,
        private readonly SpielerZugangsListeRepository $zugangsListeRepo,
        private readonly LobbyMercurePublisher $mercurePublisher,
        private readonly SpielMercurePublisher $spielPublisher,
        private readonly SpielRepository $spielRepo,
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

        // Mensch beitritt → menschenloseSeitAm zurücksetzen
        if ($tisch->getMenschenloseSeitAm() !== null) {
            $tisch->setMenschenloseSeitAm(null);
            $this->em->flush();
        }

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

    /**
     * Setzt das "Nach Spiel verlassen"-Flag für einen aktiven Spieler.
     * Nur während eines laufenden Spiels sinnvoll — zwischen Spielen direkt verlassen.
     */
    public function nachSpielVerlassenToggle(Tisch $tisch, User $user): bool
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null || !$tischSpieler->istAktiv()) {
            return false;
        }

        $neuerWert = !$tischSpieler->isMoechteNachSpielVerlassen();
        $tischSpieler->setMoechteNachSpielVerlassen($neuerWert);
        $this->em->flush();

        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
        if ($spiel !== null) {
            $this->spielPublisher->tischZustandAktualisiert($spiel);
        }

        return $neuerWert;
    }

    /**
     * Aktualisiert das Regelwerk des Tisches (nur zwischen Spielen; Caller muss das prüfen).
     *
     * @param array<string, mixed> $regelwerk
     */
    public function regelwerkAktualisieren(Tisch $tisch, array $regelwerk, User $user): void
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null || !$tischSpieler->istAktiv()) {
            return;
        }

        $tisch->setRegelEinstellungen($regelwerk);
        $this->em->flush();
        $this->mercurePublisher->lobbyAktualisiert();
    }

    /** Setzt autoStart des Tisches und aktualisiert naechsterSpielstartAm entsprechend. */
    public function autoStartToggle(Tisch $tisch, User $user): bool
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null || !$tischSpieler->istAktiv()) {
            return $tisch->isAutoStart();
        }

        $neuerWert = !$tisch->isAutoStart();
        $tisch->setAutoStart($neuerWert);

        // Countdown abbrechen wenn autoStart deaktiviert wird
        if (!$neuerWert) {
            $tisch->setNaechsterSpielstartAm(null);
        }

        $this->em->flush();
        $this->mercurePublisher->lobbyAktualisiert();

        return $neuerWert;
    }

    /**
     * Füllt freie Sitzplätze mit Bots auf. Nur wenn keine Warteschlange und kein laufendes Spiel.
     *
     * @return int Anzahl hinzugefügter Bots
     */
    public function botsAuffuellen(Tisch $tisch, User $user): int
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null || !$tischSpieler->istAktiv()) {
            throw new \DomainException('Nur aktive Spieler können Bots hinzufügen.');
        }

        if ($tisch->getWarteschlange()->count() > 0) {
            throw new \DomainException('Bots können nicht hinzugefügt werden solange Spieler in der Warteschlange sind.');
        }

        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
        if ($spiel !== null) {
            throw new \DomainException('Bots können nur zwischen Spielen hinzugefügt werden.');
        }

        $belegtePlätze = array_map(
            fn($ts) => $ts->getSitzplatz(),
            $tisch->getAktiveSpieler()->toArray()
        );

        $anzahl = 0;
        foreach (range(1, 4) as $platz) {
            if (in_array($platz, $belegtePlätze, true)) {
                continue;
            }

            $bot = new TischSpieler();
            $bot->setTisch($tisch);
            $bot->setUser(null);
            $bot->setSitzplatz($platz);
            $bot->setIstBot(true);
            $this->em->persist($bot);
            $anzahl++;
        }

        if ($anzahl > 0) {
            $this->em->flush();
            $this->mercurePublisher->lobbyAktualisiert();
        }

        return $anzahl;
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
