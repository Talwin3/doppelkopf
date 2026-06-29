<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\TischGesperrtException;
use App\Domain\Doppelkopf\Service\BotNamenProvider;
use App\Domain\Doppelkopf\Exception\TischVerlassenGesperrtException;
use App\Domain\Doppelkopf\Exception\TischZugangVerweigertException;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\TischSteuerungsModus;
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
        private readonly BotNamenProvider $botNamenProvider,
        private readonly TischProtokollService $protokoll,
    ) {}

    public function erstelleTisch(
        User $ersteller,
        string $name,
        ZugangsModusTyp $zugangsmodus,
        TischSteuerungsModus $steuerungsModus = TischSteuerungsModus::ALLE,
    ): Tisch {
        if ($this->tischSpielerRepo->findAktiveMitgliedschaft($ersteller) !== null) {
            throw TischZugangVerweigertException::weilAnAnderemTisch();
        }

        $tisch = new Tisch();
        $tisch->setName($name);
        $tisch->setErsteller($ersteller);
        $tisch->setZugangsmodus($zugangsmodus);
        $tisch->setSteuerungsModus($steuerungsModus);

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

        // Ein-Tisch-Regel: kein Beitritt, wenn bereits an einem anderen Tisch aktiv
        if ($this->tischSpielerRepo->findAktiveMitgliedschaft($user) !== null) {
            throw TischZugangVerweigertException::weilAnAnderemTisch();
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

        $this->protokoll->ereignis($tisch, $tischSpieler->getSitzplatz() !== null
            ? sprintf('%s setzt sich an den Tisch.', $tischSpieler->getAnzeigeName())
            : sprintf('%s wartet in der Warteschlange.', $tischSpieler->getAnzeigeName()));

        return $tischSpieler;
    }

    public function verlassen(Tisch $tisch, User $user): void
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null) {
            return;
        }

        // Ein aktiver (sitzender) Spieler darf einen Tisch nicht verlassen,
        // solange ein Spiel läuft — das würde das laufende Spiel zerstören.
        // Wer raus will, nutzt „Nach Spiel verlassen“ (moechteNachSpielVerlassen).
        if ($tischSpieler->istAktiv() && $this->spielRepo->findLaufendesSpielFuerTisch($tisch) !== null) {
            throw new TischVerlassenGesperrtException();
        }

        $freigegebenerPlatz = $tischSpieler->getSitzplatz();
        $verlasserName      = $tischSpieler->getAnzeigeName();

        $this->em->remove($tischSpieler);
        // Auch aus der In-Memory-Collection lösen, damit hatMenschAmTisch() unten
        // den korrekten Stand sieht (em->remove allein leert die Collection nicht).
        $tisch->getSpieler()->removeElement($tischSpieler);
        $this->em->flush();

        // Nur protokollieren, wenn noch jemand am Tisch ist, der es lesen kann.
        if ($tisch->hatMenschAmTisch()) {
            $this->protokoll->ereignis($tisch, sprintf('%s verlässt den Tisch.', $verlasserName));
        }

        if ($freigegebenerPlatz !== null) {
            $this->warteschlangeNachruecken($tisch, $freigegebenerPlatz);
        }

        // Tisch als menschenlos markieren, wenn der letzte Mensch gegangen ist —
        // sonst räumt der Worker (findMenschenlose) den Tisch nie auf.
        if (!$tisch->hatMenschAmTisch()) {
            if ($tisch->getMenschenloseSeitAm() === null) {
                $tisch->setMenschenloseSeitAm(new \DateTimeImmutable());
                $this->em->flush();
            }
        } elseif ($tisch->getMenschenloseSeitAm() !== null) {
            $tisch->setMenschenloseSeitAm(null);
            $this->em->flush();
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

        $this->protokoll->ereignis(
            $tisch,
            $neuerWert
                ? sprintf('%s verlässt den Tisch nach diesem Spiel.', $tischSpieler->getAnzeigeName())
                : sprintf('%s bleibt doch am Tisch.', $tischSpieler->getAnzeigeName()),
        );

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

        $this->protokoll->ereignis(
            $tisch,
            sprintf('%s hat das Regelwerk geändert.', $tischSpieler->getAnzeigeName()),
        );
    }

    /**
     * Legt fest, wer die Tisch-Einstellungen ändern darf. Nur der Ersteller.
     * @throws \DomainException wenn der Nutzer nicht der Ersteller ist
     */
    public function steuerungsModusSetzen(Tisch $tisch, User $user, TischSteuerungsModus $modus): void
    {
        if ($tisch->getErsteller()->getId() != $user->getId()) {
            throw new \DomainException('Nur der Tischersteller kann festlegen, wer den Tisch steuern darf.');
        }

        $tisch->setSteuerungsModus($modus);
        $this->em->flush();
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

        // Bereits vergebene Bot-Namen am Tisch sammeln, um Dopplungen zu vermeiden.
        $vergebeneNamen = array_values(array_filter(array_map(
            fn(TischSpieler $ts) => $ts->getBotName(),
            $tisch->getAktiveSpieler()->toArray(),
        )));

        $anzahl = 0;
        foreach (range(1, 4) as $platz) {
            if (in_array($platz, $belegtePlätze, true)) {
                continue;
            }

            $name = $this->botNamenProvider->zufaelligerName($vergebeneNamen);
            $vergebeneNamen[] = $name;

            $bot = new TischSpieler();
            $bot->setTisch($tisch);
            $bot->setUser(null);
            $bot->setSitzplatz($platz);
            $bot->setIstBot(true);
            $bot->setBotName($name);
            $this->em->persist($bot);
            $anzahl++;
        }

        if ($anzahl > 0) {
            $this->em->flush();
            $this->mercurePublisher->lobbyAktualisiert();

            $this->protokoll->ereignis($tisch, $anzahl === 1
                ? '1 Bot wurde hinzugefügt.'
                : sprintf('%d Bots wurden hinzugefügt.', $anzahl));
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
        $this->protokoll->ereignis(
            $tisch,
            sprintf('%s rückt aus der Warteschlange an den Tisch.', $naechster->getAnzeigeName()),
        );
    }
}
