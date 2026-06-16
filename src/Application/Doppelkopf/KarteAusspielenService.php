<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\UngueltigerZugException;
use App\Domain\Doppelkopf\Service\PunkteZaehler;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly TrumpfOrdnung $trumpfOrdnung,
        private readonly StichGewinner $stichGewinner,
        private readonly PunkteZaehler $punkteZaehler,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly SpielAbschlussService $abschlussService,
    ) {}

    public function spielen(Spiel $spiel, User $user, string $karteId): void
    {
        if ($spiel->istBeendet()) {
            throw new UngueltigerZugException('Das Spiel ist bereits beendet.');
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null) {
            throw new UngueltigerZugException('Du nimmst nicht an diesem Spiel teil.');
        }

        if ($teilnehmer->getSitzplatz() !== $spiel->getAktuellerSpielerSitzplatz()) {
            throw new UngueltigerZugException('Du bist nicht an der Reihe.');
        }

        $this->spielenMitTeilnehmer($spiel, $teilnehmer, $karteId);
    }

    /** Karte für einen Bot oder Timeout-Stellvertreter ausspielen (ohne User-Prüfung). */
    public function spielenAlsBot(Spiel $spiel, int $sitzplatz, string $karteId): void
    {
        if ($spiel->istBeendet()) {
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
        $gespielteIds = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $hand         = $teilnehmer->aktuelleHand($gespielteIds);

        $stichKarten = $this->gespielteKarteRepo->findAktuellerStich($spiel, $spiel->getAktuellerStichNr());
        if (empty($stichKarten)) {
            return $hand;
        }

        $erstgespielt        = $stichKarten[0]->alsKarte();
        $angespieltIstTrumpf = $this->trumpfOrdnung->istTrumpf($erstgespielt);
        $angespielteFarbe    = $this->trumpfOrdnung->fehlfarbe($erstgespielt);

        if ($angespieltIstTrumpf) {
            $trumpfKarten = array_filter($hand, fn(Karte $k) => $this->trumpfOrdnung->istTrumpf($k));
            return !empty($trumpfKarten) ? array_values($trumpfKarten) : $hand;
        }

        $anfarbe = array_filter(
            $hand,
            fn(Karte $k) => $this->trumpfOrdnung->fehlfarbe($k) === $angespielteFarbe,
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

        if ($position === 4) {
            $this->stichAbschliessen($spiel, $stichKartenBisher, $gespielteKarte);
        } else {
            $naechster = ($teilnehmer->getSitzplatz() % 4) + 1;
            $spiel->setAktuellerSpielerSitzplatz($naechster);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
            $this->em->flush();
        }

        $this->mercurePublisher->spielAktualisiert($spiel);
    }

    private function stichAbschliessen(Spiel $spiel, array $bisherige, GespielteKarte $letzteKarte): void
    {
        $alleStichKarten = array_merge($bisherige, [$letzteKarte]);

        // Sitzplatz → Karte für StichGewinner
        $kartenFuerGewinner = [];
        foreach ($alleStichKarten as $gk) {
            $kartenFuerGewinner[$gk->getSitzplatz()] = $gk->alsKarte();
        }

        $gewinnerSitzplatz = $this->stichGewinner->bestimme($kartenFuerGewinner);

        // Hochzeit: erster Stich den ein KONTRA-Spieler gewinnt → wird RE-Partner
        if ($spiel->getVariante() === SpielVariante::HOCHZEIT && !$spiel->isHochzeitAufgeloest()) {
            $gewinner = $spiel->getTeilnehmerBySitzplatz($gewinnerSitzplatz);
            if ($gewinner?->getTeam() === Team::KONTRA) {
                $gewinner->setTeam(Team::RE);
                $spiel->setHochzeitAufgeloest(true);
            }
        }

        $naechsterStich = $spiel->getAktuellerStichNr() + 1;

        if ($naechsterStich > 12) {
            // Alle 12 Stiche gespielt → Spiel beenden
            $this->em->flush();
            $this->abschlussService->abschliessen($spiel);
        } else {
            $spiel->setAktuellerStichNr($naechsterStich);
            $spiel->setAktuellerSpielerSitzplatz($gewinnerSitzplatz);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    private function validiereZug(Spiel $spiel, int $sitzplatz, array $hand, Karte $zuSpielen): void
    {
        $stichKarten = $this->gespielteKarteRepo->findAktuellerStich($spiel, $spiel->getAktuellerStichNr());

        if (empty($stichKarten)) {
            return; // Erste Karte im Stich → immer erlaubt
        }

        $erstgesp       = $stichKarten[0]->alsKarte();
        $angespieltIstTrumpf = $this->trumpfOrdnung->istTrumpf($erstgesp);
        $angespielteFarbe    = $this->trumpfOrdnung->fehlfarbe($erstgesp);

        $hatTrumpf   = $this->hatKartenVom($hand, fn(Karte $k) => $this->trumpfOrdnung->istTrumpf($k));
        $hatAnfarbe  = !$angespieltIstTrumpf && $this->hatKartenVom(
            $hand,
            fn(Karte $k) => $this->trumpfOrdnung->fehlfarbe($k) === $angespielteFarbe,
        );

        if ($angespieltIstTrumpf && $hatTrumpf && !$this->trumpfOrdnung->istTrumpf($zuSpielen)) {
            throw new UngueltigerZugException('Trumpf wurde angespielt – du musst Trumpf bedienen.');
        }

        if (!$angespieltIstTrumpf && $hatAnfarbe && $this->trumpfOrdnung->fehlfarbe($zuSpielen) !== $angespielteFarbe) {
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
