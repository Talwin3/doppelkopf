<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\SpielTeilnehmerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ArmutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly SpielStartService $spielStartService,
        private readonly SpielMercurePublisher $mercurePublisher,
    ) {}

    /**
     * Startet die Armut-Anfragephase: Spieler werden reihum gefragt ob sie annehmen.
     * Aufgerufen von SpielTypResolver wenn Armut-Vorbehalt erkannt wird.
     */
    public function anfrageStarten(Spiel $spiel, SpielTeilnehmer $armutSpieler): void
    {
        $ordnung = new NormalspielTrumpfOrdnung();
        $trumpfIds = [];
        foreach ($armutSpieler->getStartkartenIds() as $id) {
            if ($ordnung->istTrumpf(Karte::vonId($id))) {
                $trumpfIds[] = $id;
            }
        }

        $spiel->setArmutSpielerSitzplatz($armutSpieler->getSitzplatz());
        $spiel->setArmutTauschKartenIds($trumpfIds);
        $spiel->setStatus(SpielStatus::ARMUT_ANFRAGE);

        $ersterKandidat = $this->naechsterKandidat($spiel, $armutSpieler->getSitzplatz());
        $spiel->setAktuellerSpielerSitzplatz($ersterKandidat);
        $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());

        $this->em->flush();
        $this->mercurePublisher->armutAnfrage($spiel);
    }

    /**
     * Spieler nimmt Armut an oder lehnt ab.
     */
    public function annehmenOderAblehnen(Spiel $spiel, User $user, bool $annehmen): void
    {
        if ($spiel->getStatus() !== SpielStatus::ARMUT_ANFRAGE) {
            throw new \DomainException('Armut-Antwort nur in der Anfragephase möglich.');
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null) {
            throw new \DomainException('Spieler ist kein Teilnehmer dieses Spiels.');
        }

        if ($teilnehmer->getSitzplatz() !== $spiel->getAktuellerSpielerSitzplatz()) {
            throw new \DomainException('Du bist nicht an der Reihe.');
        }

        if ($teilnehmer->getSitzplatz() === $spiel->getArmutSpielerSitzplatz()) {
            throw new \DomainException('Der Armut-Spieler kann nicht selbst annehmen.');
        }

        $teilnehmer->setArmutAntwort($annehmen);

        if ($annehmen) {
            $spiel->setArmutAnnehmerSitzplatz($teilnehmer->getSitzplatz());
            $spiel->setStatus(SpielStatus::ARMUT_TAUSCH);
            $spiel->setAktuellerSpielerSitzplatz($teilnehmer->getSitzplatz());
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
            $this->em->flush();
            $this->mercurePublisher->armutAngenommen($spiel);
            return;
        }

        $naechster = $this->naechsterKandidat($spiel, $teilnehmer->getSitzplatz());
        if ($naechster === null) {
            $this->niemandNimmtAn($spiel);
            return;
        }

        $spiel->setAktuellerSpielerSitzplatz($naechster);
        $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
        $this->em->flush();
        $this->mercurePublisher->armutAnfrage($spiel);
    }

    /**
     * Bot antwortet auf Armut-Anfrage.
     */
    public function antwortenAlsBot(Spiel $spiel, int $sitzplatz): void
    {
        $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
        if ($teilnehmer === null) {
            return;
        }

        // Bot-Logik: annehmen wenn der Bot ≥5 Trümpfe hat (hat genug eigene Stärke)
        $ordnung = new NormalspielTrumpfOrdnung();
        $trumpfAnzahl = 0;
        foreach ($teilnehmer->getStartkartenIds() as $id) {
            if ($ordnung->istTrumpf(Karte::vonId($id))) {
                $trumpfAnzahl++;
            }
        }

        $annehmen = $trumpfAnzahl >= 5;
        $teilnehmer->setArmutAntwort($annehmen);

        if ($annehmen) {
            $spiel->setArmutAnnehmerSitzplatz($sitzplatz);
            $spiel->setStatus(SpielStatus::ARMUT_TAUSCH);
            $spiel->setAktuellerSpielerSitzplatz($sitzplatz);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
            $this->em->flush();
            $this->mercurePublisher->armutAngenommen($spiel);
            $this->botKartenTauschen($spiel, $teilnehmer);
            return;
        }

        $naechster = $this->naechsterKandidat($spiel, $sitzplatz);
        if ($naechster === null) {
            $this->niemandNimmtAn($spiel);
            return;
        }

        $spiel->setAktuellerSpielerSitzplatz($naechster);
        $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());
        $this->em->flush();
        $this->mercurePublisher->armutAnfrage($spiel);
    }

    /**
     * Annehmer gibt Karten zurück und bekommt die Armut-Trümpfe.
     *
     * @param string[] $zurueckKartenIds Karten-IDs die der Annehmer zurückgibt
     */
    public function kartenZurueckgeben(Spiel $spiel, User $user, array $zurueckKartenIds): void
    {
        if ($spiel->getStatus() !== SpielStatus::ARMUT_TAUSCH) {
            throw new \DomainException('Kartentausch nur in der Tauschphase möglich.');
        }

        $annehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($annehmer === null || $annehmer->getSitzplatz() !== $spiel->getArmutAnnehmerSitzplatz()) {
            throw new \DomainException('Nur der Annehmer kann Karten zurückgeben.');
        }

        $this->tauschDurchfuehren($spiel, $annehmer, $zurueckKartenIds);
    }

    /**
     * Zählt Trümpfe auf der Starthand (Normalspiel-Ordnung).
     */
    public static function trumpfAnzahl(SpielTeilnehmer $teilnehmer): int
    {
        $ordnung = new NormalspielTrumpfOrdnung();
        $anzahl = 0;
        foreach ($teilnehmer->getStartkartenIds() as $id) {
            if ($ordnung->istTrumpf(Karte::vonId($id))) {
                $anzahl++;
            }
        }
        return $anzahl;
    }

    private function tauschDurchfuehren(Spiel $spiel, SpielTeilnehmer $annehmer, array $zurueckKartenIds): void
    {
        $trumpfIds = $spiel->getArmutTauschKartenIds();
        $erwartet = count($trumpfIds);

        if (count($zurueckKartenIds) !== $erwartet) {
            throw new \DomainException("Du musst genau {$erwartet} Karten zurückgeben.");
        }

        $annehmerIds = $annehmer->getStartkartenIds();
        foreach ($zurueckKartenIds as $id) {
            if (!in_array($id, $annehmerIds, true) && !in_array($id, $trumpfIds, true)) {
                throw new \DomainException("Karte {$id} ist nicht auf deiner Hand.");
            }
        }

        $armutSpieler = $spiel->getTeilnehmerBySitzplatz($spiel->getArmutSpielerSitzplatz());

        // Annehmer: entferne zurückgegebene Karten, füge Armut-Trümpfe hinzu
        $neueAnnehmerIds = array_values(array_diff($annehmerIds, $zurueckKartenIds));
        $neueAnnehmerIds = array_merge($neueAnnehmerIds, $trumpfIds);
        $annehmer->setStartkartenIds($neueAnnehmerIds);

        // Armut-Spieler: entferne Trümpfe, füge zurückgegebene Karten hinzu
        $armutIds = $armutSpieler->getStartkartenIds();
        $neueArmutIds = array_values(array_diff($armutIds, $trumpfIds));
        $neueArmutIds = array_merge($neueArmutIds, $zurueckKartenIds);
        $armutSpieler->setStartkartenIds($neueArmutIds);

        // Teams setzen
        foreach ($spiel->getTeilnehmer() as $t) {
            $istRe = $t->getSitzplatz() === $spiel->getArmutSpielerSitzplatz()
                  || $t->getSitzplatz() === $spiel->getArmutAnnehmerSitzplatz();
            $t->setTeam($istRe ? Team::RE : Team::KONTRA);
        }

        // Spiel starten (Normalspiel-Variante, Vorhand kommt raus)
        $spiel->setVariante(SpielVariante::NORMALSPIEL);
        $spiel->setStatus(SpielStatus::LAUFEND);
        $spiel->setAktuellerSpielerSitzplatz(1);
        $spiel->setAktuellerStichNr(1);
        $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());

        $this->em->flush();
        $this->mercurePublisher->spielGestartet($spiel);
    }

    private function botKartenTauschen(Spiel $spiel, SpielTeilnehmer $annehmer): void
    {
        $trumpfIds = $spiel->getArmutTauschKartenIds();
        $erwartet = count($trumpfIds);

        // Bot-Strategie: schwächste Nicht-Trumpf-Karten zurückgeben
        $ordnung = new NormalspielTrumpfOrdnung();
        $annehmerIds = $annehmer->getStartkartenIds();

        $nichtTrumpf = [];
        foreach ($annehmerIds as $id) {
            $karte = Karte::vonId($id);
            if (!$ordnung->istTrumpf($karte)) {
                $nichtTrumpf[] = ['id' => $id, 'augen' => $karte->augen()];
            }
        }

        usort($nichtTrumpf, fn($a, $b) => $a['augen'] <=> $b['augen']);

        $zurueck = [];
        foreach ($nichtTrumpf as $item) {
            if (count($zurueck) >= $erwartet) {
                break;
            }
            $zurueck[] = $item['id'];
        }

        // Falls nicht genug Nicht-Trümpfe: schwächste Trümpfe zurückgeben
        if (count($zurueck) < $erwartet) {
            $trumpfKarten = [];
            foreach ($annehmerIds as $id) {
                $karte = Karte::vonId($id);
                if ($ordnung->istTrumpf($karte) && !in_array($id, $zurueck, true)) {
                    $trumpfKarten[] = ['id' => $id, 'rang' => $ordnung->trumpfRang($karte)];
                }
            }
            usort($trumpfKarten, fn($a, $b) => $a['rang'] <=> $b['rang']);
            foreach ($trumpfKarten as $item) {
                if (count($zurueck) >= $erwartet) {
                    break;
                }
                $zurueck[] = $item['id'];
            }
        }

        $this->tauschDurchfuehren($spiel, $annehmer, $zurueck);
    }

    private function niemandNimmtAn(Spiel $spiel): void
    {
        // DDV: Karten werden neu gemischt
        $spiel->setStatus(SpielStatus::BEENDET);
        $spiel->setBeendetAm(new \DateTimeImmutable());

        foreach ($spiel->getTeilnehmer() as $t) {
            $t->setGewonnen(null);
            $t->setPunkteDelta(0);
        }

        $this->em->flush();
        $this->mercurePublisher->armutAbgelehnt($spiel);

        // Neues Spiel starten
        $this->spielStartService->starten($spiel->getTisch());
    }

    /**
     * Nächster Spieler der gefragt werden kann (überspringt den Armut-Spieler und bereits Befragte).
     */
    private function naechsterKandidat(Spiel $spiel, int $abSitzplatz): ?int
    {
        $armutSitz = $spiel->getArmutSpielerSitzplatz();

        for ($i = 1; $i <= 3; $i++) {
            $kandidat = (($abSitzplatz - 1 + $i) % 4) + 1;
            if ($kandidat === $armutSitz) {
                continue;
            }
            $teilnehmer = $spiel->getTeilnehmerBySitzplatz($kandidat);
            if ($teilnehmer !== null && $teilnehmer->getArmutAntwort() === null) {
                return $kandidat;
            }
        }

        return null;
    }
}
