<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\Exception\UngueltigeAnsageException;
use App\Entity\Spiel;
use App\Entity\SpielAnsage;
use App\Entity\User;
use App\Enum\AnsageTyp;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielAnsageRepository;
use App\Repository\SpielTeilnehmerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AnsageService
{
    /** Mindestanzahl verbleibender Karten je Ansage-Typ (DDV-Timing-Regel). */
    private const TIMING = [
        'RE'          => 11,
        'CONTRA'      => 11,
        'KEINE_NEUN'  => 10,
        'KEINE_SECHS' => 9,
        'KEINE_DREI'  => 8,
        'SCHWARZ'     => 7,
    ];

    /**
     * Stacking: Welche Ansage-Werte muss das Team vorher gemacht haben?
     * @var array<string, string[]>
     */
    private const VORAUSSETZUNG = [
        'KEINE_NEUN'  => ['RE', 'CONTRA'],
        'KEINE_SECHS' => ['KEINE_NEUN'],
        'KEINE_DREI'  => ['KEINE_SECHS'],
        'SCHWARZ'     => ['KEINE_DREI'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielAnsageRepository $ansageRepo,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly TischProtokollService $protokoll,
    ) {}

    /**
     * Ist bei einer angemeldeten Hochzeit der Klärungsstich noch offen? Dann ist keine
     * Ansage erlaubt (TSR 4.4.4: „Die Erstansage ist erst erlaubt, nachdem der
     * Klärungsstich vollendet wurde"). Die „Stille Hochzeit" läuft als Normalspiel und
     * ist davon nicht betroffen.
     */
    private function wartetAufKlaerungsstich(Spiel $spiel): bool
    {
        return $spiel->getVariante() === SpielVariante::HOCHZEIT
            && $spiel->getHochzeitKlaerungsStichNr() === null;
    }

    /**
     * Nachlass auf die Ansagefristen nach TSR 6.4.2: Zog sich die Hochzeit über mehrere
     * Stiche hin, bekommen alle Spieler die verlorene Zeit zurück – je Stich, den der
     * Klärungsstich nach hinten rückte, eine Karte weniger auf der Hand.
     */
    private function klaerungsNachlass(Spiel $spiel): int
    {
        if ($spiel->getVariante() !== SpielVariante::HOCHZEIT) {
            return 0;
        }

        return max(0, ($spiel->getHochzeitKlaerungsStichNr() ?? 1) - 1);
    }

    /** Mindestzahl der Handkarten für eine Ansage, inklusive Hochzeits-Nachlass. */
    private function mindestKarten(Spiel $spiel, AnsageTyp $typ): int
    {
        return (self::TIMING[$typ->value] ?? 0) - $this->klaerungsNachlass($spiel);
    }

    public function machen(Spiel $spiel, User $user, AnsageTyp $typ): void
    {
        if ($spiel->getStatus() !== SpielStatus::LAUFEND) {
            throw new UngueltigeAnsageException('Ansagen sind nur in einem laufenden Spiel möglich.');
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null) {
            throw new UngueltigeAnsageException('Du nimmst nicht an diesem Spiel teil.');
        }

        $team = $teilnehmer->getTeam();
        if ($team === null) {
            throw new UngueltigeAnsageException('Dein Team ist noch nicht bestimmt.');
        }

        if ($typ === AnsageTyp::RE && $team !== Team::RE) {
            throw new UngueltigeAnsageException('Nur RE-Spieler dürfen "Re" ansagen.');
        }
        if ($typ === AnsageTyp::CONTRA && $team !== Team::KONTRA) {
            throw new UngueltigeAnsageException('Nur KONTRA-Spieler dürfen "Contra" ansagen.');
        }

        if ($this->ansageRepo->hatBereitsAngesagt($spiel, $teilnehmer->getSitzplatz(), $typ)) {
            throw new UngueltigeAnsageException('Diese Ansage hast du bereits gemacht.');
        }

        $gespielteIds     = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $verbleibenKarten = count($teilnehmer->getStartkartenIds()) - count($gespielteIds);

        if ($this->wartetAufKlaerungsstich($spiel)) {
            throw new UngueltigeAnsageException(
                'Bei einer Hochzeit sind Ansagen erst nach dem Klärungsstich erlaubt.',
            );
        }

        $minKarten = $this->mindestKarten($spiel, $typ);

        if ($verbleibenKarten < $minKarten) {
            throw new UngueltigeAnsageException(sprintf(
                'Zu spät – für "%s" müssen noch mindestens %d Karten auf der Hand sein (du hast %d).',
                $typ->value, $minKarten, $verbleibenKarten,
            ));
        }

        if (isset(self::VORAUSSETZUNG[$typ->value])) {
            $teamSitzplaetze = $this->teamSitzplaetze($spiel, $team);
            $erfuellt        = false;
            foreach (self::VORAUSSETZUNG[$typ->value] as $vorWert) {
                if ($this->ansageRepo->teamHatAngesagt($spiel, $teamSitzplaetze, AnsageTyp::from($vorWert))) {
                    $erfuellt = true;
                    break;
                }
            }
            if (!$erfuellt) {
                throw new UngueltigeAnsageException(sprintf(
                    'Vor "%s" muss das Team erst %s ansagen.',
                    $typ->value,
                    implode(' oder ', self::VORAUSSETZUNG[$typ->value]),
                ));
            }
        }

        // Spieler handelt selbst → evtl. laufende Bot-Vertretung beenden.
        $this->protokoll->spielerZurueck($teilnehmer);

        $ansage = new SpielAnsage();
        $ansage->setSpiel($spiel);
        $ansage->setSitzplatz($teilnehmer->getSitzplatz());
        $ansage->setAnsageTyp($typ);
        $ansage->setStichNrBeiAnsage($spiel->getAktuellerStichNr());
        $ansage->setKartenNochInHand($verbleibenKarten);

        $this->em->persist($ansage);
        $this->em->flush();

        $this->mercurePublisher->ansageGemacht($spiel);
        $this->protokoll->ereignis(
            $spiel->getTisch(),
            sprintf('%s sagt %s an.', $teilnehmer->getAnzeigeName(), $typ->label()),
        );
    }

    /**
     * Bot-Variante für Re/Contra: prüft die Regeln (Team, Timing, Dopplung) und macht
     * die Ansage, sofern zulässig. Gibt true zurück, wenn angesagt wurde.
     */
    public function machenAlsBot(Spiel $spiel, int $sitzplatz, AnsageTyp $typ): bool
    {
        if ($spiel->getStatus() !== SpielStatus::LAUFEND) {
            return false;
        }
        if ($typ !== AnsageTyp::RE && $typ !== AnsageTyp::CONTRA) {
            return false; // Bots machen aktuell nur Re/Contra
        }

        $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
        if ($teilnehmer === null) {
            return false;
        }

        $team = $teilnehmer->getTeam();
        if ($team === null
            || ($typ === AnsageTyp::RE && $team !== Team::RE)
            || ($typ === AnsageTyp::CONTRA && $team !== Team::KONTRA)
        ) {
            return false;
        }

        if ($this->ansageRepo->hatBereitsAngesagt($spiel, $sitzplatz, $typ)) {
            return false;
        }

        $gespielteIds     = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $sitzplatz);
        $verbleibenKarten = count($teilnehmer->getStartkartenIds()) - count($gespielteIds);
        if ($verbleibenKarten < (self::TIMING[$typ->value] ?? 0)) {
            return false; // zu spät
        }

        $ansage = new SpielAnsage();
        $ansage->setSpiel($spiel);
        $ansage->setSitzplatz($sitzplatz);
        $ansage->setAnsageTyp($typ);
        $ansage->setStichNrBeiAnsage($spiel->getAktuellerStichNr());
        $ansage->setKartenNochInHand($verbleibenKarten);

        $this->em->persist($ansage);
        $this->em->flush();

        $this->mercurePublisher->ansageGemacht($spiel);
        $this->protokoll->ereignis(
            $spiel->getTisch(),
            sprintf('%s sagt %s an.', $teilnehmer->getAnzeigeName(), $typ->label()),
        );

        return true;
    }

    /**
     * Welche Ansagen kann dieser Spieler aktuell noch machen?
     * @return AnsageTyp[]
     */
    public function verfuegbareAnsagen(Spiel $spiel, User $user): array
    {
        if ($spiel->getStatus() !== SpielStatus::LAUFEND) {
            return [];
        }

        // Bei angemeldeter Hochzeit ist vor dem Klärungsstich gar nichts möglich.
        if ($this->wartetAufKlaerungsstich($spiel)) {
            return [];
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer === null || $teilnehmer->getTeam() === null) {
            return [];
        }

        $team             = $teilnehmer->getTeam();
        $gespielteIds     = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $verbleibenKarten = count($teilnehmer->getStartkartenIds()) - count($gespielteIds);
        $teamSitzplaetze  = $this->teamSitzplaetze($spiel, $team);
        $verfuegbar       = [];

        foreach (self::TIMING as $typWert => $basisMinKarten) {
            $minKarten = $basisMinKarten - $this->klaerungsNachlass($spiel);
            if ($verbleibenKarten < $minKarten) {
                continue;
            }

            $typ = AnsageTyp::from($typWert);

            if ($typ === AnsageTyp::RE && $team !== Team::RE) continue;
            if ($typ === AnsageTyp::CONTRA && $team !== Team::KONTRA) continue;

            if ($this->ansageRepo->hatBereitsAngesagt($spiel, $teilnehmer->getSitzplatz(), $typ)) {
                continue;
            }

            if (isset(self::VORAUSSETZUNG[$typWert])) {
                $erfuellt = false;
                foreach (self::VORAUSSETZUNG[$typWert] as $vorWert) {
                    if ($this->ansageRepo->teamHatAngesagt($spiel, $teamSitzplaetze, AnsageTyp::from($vorWert))) {
                        $erfuellt = true;
                        break;
                    }
                }
                if (!$erfuellt) continue;
            }

            $verfuegbar[] = $typ;
        }

        return $verfuegbar;
    }

    /** @return int[] */
    private function teamSitzplaetze(Spiel $spiel, Team $team): array
    {
        return array_values(array_map(
            fn($t) => $t->getSitzplatz(),
            array_filter(
                $spiel->getTeilnehmer()->toArray(),
                fn($t) => $t->getTeam() === $team,
            ),
        ));
    }
}
