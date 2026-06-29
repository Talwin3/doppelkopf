<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\UngueltigerZugException;
use App\Domain\Doppelkopf\Service\PunkteZaehler;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\GespielteKarte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielTeilnehmerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class KarteAusspielenService
{
    /** Sekunden, die der Endstich sichtbar bleibt, bevor der Worker das Spiel abschließt. */
    private const ABSCHLUSS_VERZOEGERUNG_SEK = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly StichGewinner $stichGewinner,
        private readonly PunkteZaehler $punkteZaehler,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly SpielAbschlussService $abschlussService,
        private readonly TischProtokollService $protokoll,
    ) {}

    public function spielen(Spiel $spiel, User $user, string $karteId): void
    {
        if ($spiel->getStatus() !== SpielStatus::LAUFEND) {
            throw new UngueltigerZugException('Karten können nur in einem laufenden Spiel gespielt werden.');
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null) {
            throw new UngueltigerZugException('Du nimmst nicht an diesem Spiel teil.');
        }

        if ($teilnehmer->getSitzplatz() !== $spiel->getAktuellerSpielerSitzplatz()) {
            throw new UngueltigerZugException('Du bist nicht an der Reihe.');
        }

        // Spieler handelt selbst → evtl. laufende Bot-Vertretung beenden.
        $this->protokoll->spielerZurueck($teilnehmer);

        $this->spielenMitTeilnehmer($spiel, $teilnehmer, $karteId);
    }

    /** Karte für einen Bot oder Timeout-Stellvertreter ausspielen (ohne User-Prüfung). */
    public function spielenAlsBot(Spiel $spiel, int $sitzplatz, string $karteId): void
    {
        if ($spiel->getStatus() !== SpielStatus::LAUFEND) {
            return;
        }

        if ($sitzplatz !== $spiel->getAktuellerSpielerSitzplatz()) {
            return;
        }

        $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
        if ($teilnehmer === null) {
            return;
        }

        $this->spielenMitTeilnehmer($spiel, $teilnehmer, $karteId);
    }

    /**
     * Erlaubte Karten für den aktuellen Zug (Bedienungspflicht berücksichtigt).
     *
     * @param Karte[] $hand
     * @return Karte[]
     */
    public function erlaubteKarten(Spiel $spiel, SpielTeilnehmer $teilnehmer): array
    {
        $ordnung      = $this->trumpfOrdnungFactory->fuerSpiel($spiel);
        $gespielteIds = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $hand         = $teilnehmer->aktuelleHand($gespielteIds);

        $stichKarten = $this->gespielteKarteRepo->findAktuellerStich($spiel, $spiel->getAktuellerStichNr());
        if (empty($stichKarten)) {
            return $hand;
        }

        $erstgespielt        = $stichKarten[0]->alsKarte();
        $angespieltIstTrumpf = $ordnung->istTrumpf($erstgespielt);
        $angespielteFarbe    = $ordnung->fehlfarbe($erstgespielt);

        if ($angespieltIstTrumpf) {
            $trumpfKarten = array_filter($hand, fn(Karte $k) => $ordnung->istTrumpf($k));
            return !empty($trumpfKarten) ? array_values($trumpfKarten) : $hand;
        }

        $anfarbe = array_filter(
            $hand,
            fn(Karte $k) => $ordnung->fehlfarbe($k) === $angespielteFarbe,
        );
        return !empty($anfarbe) ? array_values($anfarbe) : $hand;
    }

    private function spielenMitTeilnehmer(Spiel $spiel, SpielTeilnehmer $teilnehmer, string $karteId): void
    {
        $gespielteIds = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $hand         = $teilnehmer->aktuelleHand($gespielteIds);

        $karte = $this->findeInHand($hand, $karteId);
        if ($karte === null) {
            throw new UngueltigerZugException('Diese Karte liegt nicht auf deiner Hand.');
        }

        $this->validiereZug($spiel, $teilnehmer->getSitzplatz(), $hand, $karte);

        $stichKartenBisher = $this->gespielteKarteRepo->findAktuellerStich($spiel, $spiel->getAktuellerStichNr());
        $position          = count($stichKartenBisher) + 1;

        $gespielteKarte = new GespielteKarte();
        $gespielteKarte->setSpiel($spiel);
        $gespielteKarte->setSitzplatz($teilnehmer->getSitzplatz());
        $gespielteKarte->setStichNr($spiel->getAktuellerStichNr());
        $gespielteKarte->setPositionImStich($position);
        $gespielteKarte->setKarteId($karteId);

        $this->em->persist($gespielteKarte);

        $sitzplatz = $teilnehmer->getSitzplatz();

        if ($position === 4) {
            $this->stichAbschliessen($spiel, $stichKartenBisher, $gespielteKarte, $sitzplatz);
        } else {
            $naechster = ($sitzplatz % 4) + 1;
            $spiel->setAktuellerSpielerSitzplatz($naechster);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
            $this->em->flush();
            $this->mercurePublisher->karteGespielt($spiel, $sitzplatz);
        }
    }

    private function stichAbschliessen(Spiel $spiel, array $bisherige, GespielteKarte $letzteKarte, int $gespielterSitzplatz): void
    {
        $alleStichKarten = array_merge($bisherige, [$letzteKarte]);

        // Sitzplatz → Karte für StichGewinner
        $kartenFuerGewinner = [];
        foreach ($alleStichKarten as $gk) {
            $kartenFuerGewinner[$gk->getSitzplatz()] = $gk->alsKarte();
        }

        $ordnung = $this->trumpfOrdnungFactory->fuerSpiel($spiel);
        $zweiteDulleSticht = (bool) ($spiel->getTisch()->getRegelEinstellungen()['zweite_dulle_sticht'] ?? false);
        $gewinnerSitzplatz = $this->stichGewinner->bestimme($kartenFuerGewinner, $ordnung, $zweiteDulleSticht);

        // Hochzeit: erster Stich den ein KONTRA-Spieler gewinnt → wird RE-Partner
        $hochzeitPartnerName = null;
        if ($spiel->getVariante() === SpielVariante::HOCHZEIT && !$spiel->isHochzeitAufgeloest()) {
            $gewinner = $spiel->getTeilnehmerBySitzplatz($gewinnerSitzplatz);
            if ($gewinner?->getTeam() === Team::KONTRA) {
                $gewinner->setTeam(Team::RE);
                $spiel->setHochzeitAufgeloest(true);
                $hochzeitPartnerName = $gewinner->getAnzeigeName();
            }
        }

        $naechsterStich = $spiel->getAktuellerStichNr() + 1;
        $maxStiche      = $spiel->getTisch()->getRegelEinstellung('ohne_neuner') ? 10 : 12;

        if ($naechsterStich > $maxStiche) {
            // Letzter Stich: Endstich wie jeden anderen Stich kurz anzeigen und den
            // Spielabschluss (Wertung) verzögert über den Worker ausführen, damit die
            // vierte Karte sichtbar bleibt, statt sofort zur Wertung zu springen.
            $spiel->setAktuellerSpielerSitzplatz($gewinnerSitzplatz);
            $spiel->setAktuellerZugBegannAm(null); // kein weiterer Zug → kein Bot-Timeout
            $spiel->setAbschlussFaelligAm(
                (new \DateTimeImmutable())->modify('+' . self::ABSCHLUSS_VERZOEGERUNG_SEK . ' seconds')
            );
            $this->em->flush();
            $this->mercurePublisher->stichAbgeschlossen($spiel, $gewinnerSitzplatz, $gespielterSitzplatz);
        } else {
            $spiel->setAktuellerStichNr($naechsterStich);
            $spiel->setAktuellerSpielerSitzplatz($gewinnerSitzplatz);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
            $this->em->flush();
            $this->mercurePublisher->stichAbgeschlossen($spiel, $gewinnerSitzplatz, $gespielterSitzplatz);
        }

        if ($hochzeitPartnerName !== null) {
            $this->protokoll->ereignis(
                $spiel->getTisch(),
                sprintf('%s wird Partner – die Hochzeit ist geklärt.', $hochzeitPartnerName),
            );
        }
    }

    private function validiereZug(Spiel $spiel, int $sitzplatz, array $hand, Karte $zuSpielen): void
    {
        $ordnung     = $this->trumpfOrdnungFactory->fuerSpiel($spiel);
        $stichKarten = $this->gespielteKarteRepo->findAktuellerStich($spiel, $spiel->getAktuellerStichNr());

        if (empty($stichKarten)) {
            return; // Erste Karte im Stich → immer erlaubt
        }

        $erstgesp            = $stichKarten[0]->alsKarte();
        $angespieltIstTrumpf = $ordnung->istTrumpf($erstgesp);
        $angespielteFarbe    = $ordnung->fehlfarbe($erstgesp);

        $hatTrumpf   = $this->hatKartenVom($hand, fn(Karte $k) => $ordnung->istTrumpf($k));
        $hatAnfarbe  = !$angespieltIstTrumpf && $this->hatKartenVom(
            $hand,
            fn(Karte $k) => $ordnung->fehlfarbe($k) === $angespielteFarbe,
        );

        if ($angespieltIstTrumpf && $hatTrumpf && !$ordnung->istTrumpf($zuSpielen)) {
            throw new UngueltigerZugException('Trumpf wurde angespielt – du musst Trumpf bedienen.');
        }

        if (!$angespieltIstTrumpf && $hatAnfarbe && $ordnung->fehlfarbe($zuSpielen) !== $angespielteFarbe) {
            throw new UngueltigerZugException(
                sprintf('Du musst %s bedienen.', $angespielteFarbe?->value ?? 'die Anspielfarbe')
            );
        }
    }

    /** @param Karte[] $hand */
    private function hatKartenVom(array $hand, callable $filter): bool
    {
        foreach ($hand as $k) {
            if ($filter($k)) return true;
        }
        return false;
    }

    /** @param Karte[] $hand */
    private function findeInHand(array $hand, string $karteId): ?Karte
    {
        foreach ($hand as $k) {
            if ($k->id() === $karteId) return $k;
        }
        return null;
    }
}
