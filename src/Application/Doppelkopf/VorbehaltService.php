<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\Service\VorbehaltHeuristik;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\VorbehaltTyp;
use App\Infrastructure\Logger\SpiellogikLogger;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\SpielTeilnehmerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class VorbehaltService
{
    /** Farbsoli, die der Bot automatisch erwägen darf (rein trumpfzahlbasiert bewertbar). */
    private const AUTO_SOLO_VARIANTEN = [
        SpielVariante::SOLO_KARO,
        SpielVariante::SOLO_HERZ,
        SpielVariante::SOLO_PIK,
        SpielVariante::SOLO_KREUZ,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly SpielTypResolver $typResolver,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly SpiellogikLogger $logger,
        private readonly TischProtokollService $protokoll,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly VorbehaltHeuristik $vorbehaltHeuristik,
    ) {}

    /**
     * Spieler deklariert seinen Vorbehalt.
     *
     * @throws \DomainException wenn Deklaration ungültig ist
     */
    public function deklarieren(Spiel $spiel, User $user, VorbehaltTyp $typ, ?SpielVariante $soloVariante = null): void
    {
        if ($spiel->getStatus() !== SpielStatus::VORBEHALT) {
            throw new \DomainException('Vorbehalt-Deklaration nur in der Vorbehaltsrunde möglich.');
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null) {
            throw new \DomainException('Spieler ist kein Teilnehmer dieses Spiels.');
        }

        if ($teilnehmer->isVorbehaltDeklariert()) {
            throw new \DomainException('Du hast bereits einen Vorbehalt deklariert.');
        }

        // Reihenfolge erzwingen: nur der aktuelle Spieler darf deklarieren
        if ($spiel->getAktuellerSpielerSitzplatz() !== $teilnehmer->getSitzplatz()) {
            throw new \DomainException('Du bist nicht an der Reihe.');
        }

        // Validierung
        $this->validieren($teilnehmer->getSitzplatz(), $spiel, $typ, $soloVariante);

        // Spieler handelt selbst → evtl. laufende Bot-Vertretung beenden.
        $this->protokoll->spielerZurueck($teilnehmer);

        $teilnehmer->setVorbehaltDeklariert(true);
        $teilnehmer->setVorbehaltTyp($typ);
        $teilnehmer->setVorbehaltSoloVariante($soloVariante);

        $this->logger->spielzugAusgefuehrt(
            (string) $spiel->getId(),
            $teilnehmer->getAnzeigeName(),
            'VORBEHALT:' . $typ->value . ($soloVariante ? ':' . $soloVariante->value : ''),
        );

        $this->protokollVorbehalt($spiel, $teilnehmer->getAnzeigeName(), $typ);

        // Prüfe ob alle deklariert haben
        $alleTeilnehmer = $spiel->getTeilnehmer()->toArray();
        $allDeklariert  = count(array_filter($alleTeilnehmer, fn($t) => $t->isVorbehaltDeklariert())) === 4;

        if ($allDeklariert) {
            $this->em->flush();
            $this->typResolver->aufloesen($spiel);
        } else {
            // Nächsten Spieler aktivieren
            $naechster = ($teilnehmer->getSitzplatz() % 4) + 1;
            $spiel->setAktuellerSpielerSitzplatz($naechster);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());

            $this->em->flush();
            $this->mercurePublisher->vorbehaltDeklariert($spiel);
        }
    }

    /** Deklariert für einen Bot-Spieler (anhand Sitzplatz). Keine Reihenfolge-Prüfung. */
    public function deklarierenAlsBot(Spiel $spiel, int $sitzplatz): void
    {
        $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
        if ($teilnehmer === null) {
            return;
        }

        if ($teilnehmer->isVorbehaltDeklariert()) {
            // Selbstheilung: Haben bereits alle deklariert, hängt das Spiel aber noch
            // in der Vorbehaltsrunde (z. B. weil eine frühere Auflösung abbrach), die
            // Auflösung erneut anstoßen statt den Bot endlos wirkungslos aufzurufen.
            $alleDeklariert = count(array_filter(
                $spiel->getTeilnehmer()->toArray(),
                fn($t) => $t->isVorbehaltDeklariert(),
            )) === 4;
            if ($alleDeklariert) {
                $this->typResolver->aufloesen($spiel);
            }
            return;
        }

        // Bot-Logik (stärkegesteuert): Hochzeit > Solo > Armut > Gesund.
        $regelwerk    = $spiel->getRegelEinstellungen();
        $hand         = $teilnehmer->aktuelleHand([]);
        $entscheidung = $this->vorbehaltHeuristik->entscheide(
            $teilnehmer->getBotStaerke(),
            $hand,
            !empty($regelwerk['armut']),
            ArmutService::trumpfAnzahl($teilnehmer),
            $this->soloTrumpfAnzahl($spiel, $hand, $regelwerk),
        );
        $typ      = $entscheidung->typ;
        $variante = $entscheidung->soloVariante;

        $teilnehmer->setVorbehaltDeklariert(true);
        $teilnehmer->setVorbehaltTyp($typ);
        $teilnehmer->setVorbehaltSoloVariante($variante);

        $this->protokollVorbehalt($spiel, $teilnehmer->getAnzeigeName(), $typ);

        $alleTeilnehmer = $spiel->getTeilnehmer()->toArray();
        $allDeklariert  = count(array_filter($alleTeilnehmer, fn($t) => $t->isVorbehaltDeklariert())) === 4;

        if ($allDeklariert) {
            $this->em->flush();
            $this->typResolver->aufloesen($spiel);
        } else {
            $naechster = ($sitzplatz % 4) + 1;
            $spiel->setAktuellerSpielerSitzplatz($naechster);
            $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());

            $this->em->flush();
            $this->mercurePublisher->vorbehaltDeklariert($spiel);
        }
    }

    /**
     * Gibt zurück welche Vorbehalt-Optionen für den Spieler an Sitzplatz X sinnvoll sind.
     *
     * @return VorbehaltTyp[]
     */
    public function verfuegbareOptionen(Spiel $spiel, User $user): array
    {
        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null || $teilnehmer->isVorbehaltDeklariert()) {
            return [];
        }

        $optionen = [VorbehaltTyp::GESUND];

        if ($teilnehmer->anzahlKreuzDamen() === 2) {
            $optionen[] = VorbehaltTyp::HOCHZEIT;
        }

        // Armut: ≤3 Trümpfe + Regel aktiv
        $regelwerk = $spiel->getRegelEinstellungen();
        if (!empty($regelwerk['armut']) && ArmutService::trumpfAnzahl($teilnehmer) <= 3) {
            $optionen[] = VorbehaltTyp::ARMUT;
        }

        // Regelwerk: welche Soli sind erlaubt?
        $soliErlaubt = $regelwerk['soli_erlaubt'] ?? [];
        if (!empty($soliErlaubt)) {
            $optionen[] = VorbehaltTyp::SOLO;
        }

        return $optionen;
    }

    /**
     * Protokolliert die Vorbehalts-Entscheidung. Die konkrete Art (Solo/Hochzeit/Armut)
     * bleibt bis zur Auflösung verdeckt — angekündigt wird nur „gesund" vs. „Vorbehalt".
     */
    private function protokollVorbehalt(Spiel $spiel, string $name, VorbehaltTyp $typ): void
    {
        $text = $typ === VorbehaltTyp::GESUND
            ? sprintf('%s ist gesund.', $name)
            : sprintf('%s meldet einen Vorbehalt.', $name);

        $this->protokoll->ereignis($spiel->getTisch(), $text);
    }

    /**
     * Zählt für jedes am Tisch erlaubte Farbsolo, wie viele Handkarten in dieser Variante
     * Trumpf wären – Grundlage der Bot-Solo-Entscheidung.
     *
     * @param Karte[]             $hand
     * @param array<string, mixed> $regelwerk
     * @return array<string, int> Varianten-Wert → Trumpfzahl
     */
    private function soloTrumpfAnzahl(Spiel $spiel, array $hand, array $regelwerk): array
    {
        $erlaubt = $regelwerk['soli_erlaubt'] ?? [];
        if (empty($erlaubt)) {
            return [];
        }

        $ergebnis = [];
        foreach (self::AUTO_SOLO_VARIANTEN as $variante) {
            if (!in_array($variante->value, $erlaubt, true)) {
                continue;
            }
            $ordnung = $this->trumpfOrdnungFactory->fuerVariante($spiel, $variante);
            $ergebnis[$variante->value] = count(array_filter($hand, fn(Karte $k) => $ordnung->istTrumpf($k)));
        }

        return $ergebnis;
    }

    private function validieren(int $sitzplatz, Spiel $spiel, VorbehaltTyp $typ, ?SpielVariante $soloVariante): void
    {
        if ($typ === VorbehaltTyp::SOLO) {
            if ($soloVariante === null) {
                throw new \DomainException('Solo-Deklaration erfordert eine Spielvariante.');
            }
            $regelwerk   = $spiel->getRegelEinstellungen();
            $soliErlaubt = $regelwerk['soli_erlaubt'] ?? [];
            if (!in_array($soloVariante->value, $soliErlaubt, true)) {
                throw new \DomainException("Solo-Variante '{$soloVariante->value}' ist an diesem Tisch nicht erlaubt.");
            }
        }

        if ($typ === VorbehaltTyp::HOCHZEIT) {
            $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
            if ($teilnehmer !== null && $teilnehmer->anzahlKreuzDamen() < 2) {
                throw new \DomainException('Hochzeit kann nur angemeldet werden wenn beide Kreuz-Damen auf der Hand sind.');
            }
        }

        if ($typ === VorbehaltTyp::ARMUT) {
            $regelwerk = $spiel->getRegelEinstellungen();
            if (empty($regelwerk['armut'])) {
                throw new \DomainException('Armut ist an diesem Tisch nicht aktiviert.');
            }
            $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
            if ($teilnehmer !== null && ArmutService::trumpfAnzahl($teilnehmer) > 3) {
                throw new \DomainException('Armut kann nur mit maximal 3 Trümpfen angemeldet werden.');
            }
        }
    }
}
