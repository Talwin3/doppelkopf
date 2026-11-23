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
use App\Enum\BotStaerke;
use App\Enum\TischPunkteAnzeige;
use App\Enum\TischSteuerungsModus;
use App\Enum\WertungsKategorie;
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
        bool $ddvTisch = false,
    ): Tisch {
        if ($this->tischSpielerRepo->findAktiveMitgliedschaft($ersteller) !== null) {
            throw TischZugangVerweigertException::weilAnAnderemTisch();
        }

        $tisch = new Tisch();
        $tisch->setName($name);
        $tisch->setErsteller($ersteller);
        $tisch->setZugangsmodus($zugangsmodus);
        $tisch->setSteuerungsModus($steuerungsModus);
        $tisch->setDdvTisch($ddvTisch);

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
        // Besetzung geändert: Punkteleiste und Panel der anderen am Tisch nachziehen.
        $this->spielPublisher->tischBesetzungGeaendert($tisch);

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
        // Besetzung geändert: Punkteleiste und Panel der anderen am Tisch nachziehen.
        $this->spielPublisher->tischBesetzungGeaendert($tisch);
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
     * @param string|null $aenderung Kennung des Clients für diese Speicherung, geht im Event mit
     */
    public function regelwerkAktualisieren(Tisch $tisch, array $regelwerk, User $user, ?string $aenderung = null): void
    {
        $tischSpieler = $this->tischSpielerRepo->findByTischAndUser($tisch, $user);
        if ($tischSpieler === null || !$tischSpieler->istAktiv()) {
            return;
        }

        $vorher = $tisch->getRegelEinstellungen();
        // Am DDV-Tisch hält Tisch::setRegelEinstellungen() die Spielregeln fest.
        $tisch->setRegelEinstellungen($regelwerk);
        $nachher = $tisch->getRegelEinstellungen();

        // Nichts geändert (z. B. am DDV-Tisch nur gesperrte Regeln abgeschickt): kein Protokoll,
        // kein Event. Verglichen werden die wirksamen Werte, ein fehlender Schlüssel zählt mit
        // seinem Standard – sonst gälte schon das erste Speichern eines alten Tisches als Änderung.
        if (self::wirksam($nachher) === self::wirksam($vorher)) {
            return;
        }

        $ohneDarstellung = static fn(array $r): array
            => array_diff_key(self::wirksam($r), array_flip(WertungsKategorie::DARSTELLUNGS_SCHLUESSEL));
        $nurDarstellung = $ohneDarstellung($vorher) === $ohneDarstellung($nachher);

        $this->em->flush();
        $this->mercurePublisher->lobbyAktualisiert();
        // Nur echte Regeländerungen machen ungespeicherte Häkchen anderer ungültig.
        $this->spielPublisher->tischAktualisiert($tisch, regelwerkGeaendert: !$nurDarstellung, aenderung: $aenderung);
        $this->protokoll->ereignis(
            $tisch,
            sprintf(
                $nurDarstellung ? '%s hat die Anzeige-Einstellungen geändert.' : '%s hat das Regelwerk geändert.',
                $tischSpieler->getAnzeigeName(),
            ),
        );
    }

    /**
     * Regelwerk mit Standardwerten für fehlende Schlüssel, nach Schlüsseln sortiert – für
     * den Vergleich „hat sich etwas geändert?“. Standard = DDV-Regeln (so startet ein
     * neuer Tisch) und Augen-Anzeige an (wie im Template).
     *
     * @param array<string, mixed> $regelwerk
     * @return array<string, mixed>
     */
    private static function wirksam(array $regelwerk): array
    {
        $r = array_merge(WertungsKategorie::DDV_REGELN, ['augen_mouseover' => true], $regelwerk);
        ksort($r);
        // Die Reihenfolge der erlaubten Soli ist bedeutungslos.
        if (is_array($r['soli_erlaubt'] ?? null)) {
            sort($r['soli_erlaubt']);
        }

        return $r;
    }

    /**
     * Schaltet die Punkteanzeige am Tisch um. Reine Anzeige, an der Wertung ändert sich nichts.
     * Die Berechtigung prüft der Controller ({@see \App\Security\Voter\TischAktionVoter}).
     */
    public function punkteAnzeigeSetzen(Tisch $tisch, TischPunkteAnzeige $anzeige): void
    {
        $tisch->setPunkteAnzeige($anzeige);
        $this->em->flush();
        $this->spielPublisher->tischAktualisiert($tisch);
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
        // Wer was darf, ändert das Einstellungs-Panel der anderen.
        $this->spielPublisher->tischAktualisiert($tisch);
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
        $this->spielPublisher->tischAktualisiert($tisch);

        return $neuerWert;
    }

    /**
     * Füllt freie Sitzplätze mit Bots auf. Nur wenn keine Warteschlange und kein laufendes Spiel.
     *
     * @param ?BotStaerke $staerke Gewünschte Spielstärke der Bots (null = Systemdefault). Nicht
     *                             implementierte Stufen fallen auf den Default zurück.
     * @return int Anzahl hinzugefügter Bots
     */
    public function botsAuffuellen(Tisch $tisch, User $user, ?BotStaerke $staerke = null): int
    {
        $staerke = ($staerke !== null && $staerke->implementiert()) ? $staerke : BotStaerke::default();

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
        $vergebeneNamen = $tisch->vergebeneBotNamen();

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
            $bot->setBotStaerke($staerke);
            $this->em->persist($bot);
            $anzahl++;
        }

        if ($anzahl > 0) {
            $this->em->flush();
            $this->mercurePublisher->lobbyAktualisiert();
            $this->spielPublisher->tischBesetzungGeaendert($tisch);

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
