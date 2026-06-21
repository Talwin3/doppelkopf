<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly SpielTypResolver $typResolver,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly SpiellogikLogger $logger,
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

        $teilnehmer->setVorbehaltDeklariert(true);
        $teilnehmer->setVorbehaltTyp($typ);
        $teilnehmer->setVorbehaltSoloVariante($soloVariante);

        $this->logger->spielzugAusgefuehrt(
            (string) $spiel->getId(),
            $teilnehmer->getUser()?->getUsername() ?? 'Bot',
            'VORBEHALT:' . $typ->value . ($soloVariante ? ':' . $soloVariante->value : ''),
        );

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

        // Bot-Logik: Hochzeit > Armut > Gesund
        $typ = VorbehaltTyp::GESUND;
        $variante = null;

        if ($teilnehmer->anzahlKreuzDamen() === 2) {
            $typ = VorbehaltTyp::HOCHZEIT;
        } else {
            $regelwerk = $spiel->getTisch()->getRegelEinstellungen();
            if (!empty($regelwerk['armut']) && ArmutService::trumpfAnzahl($teilnehmer) <= 3) {
                $typ = VorbehaltTyp::ARMUT;
            }
        }

        $teilnehmer->setVorbehaltDeklariert(true);
        $teilnehmer->setVorbehaltTyp($typ);
        $teilnehmer->setVorbehaltSoloVariante($variante);

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
        $regelwerk = $spiel->getTisch()->getRegelEinstellungen();
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

    private function validieren(int $sitzplatz, Spiel $spiel, VorbehaltTyp $typ, ?SpielVariante $soloVariante): void
    {
        if ($typ === VorbehaltTyp::SOLO) {
            if ($soloVariante === null) {
                throw new \DomainException('Solo-Deklaration erfordert eine Spielvariante.');
            }
            $regelwerk   = $spiel->getTisch()->getRegelEinstellungen();
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
            $regelwerk = $spiel->getTisch()->getRegelEinstellungen();
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
