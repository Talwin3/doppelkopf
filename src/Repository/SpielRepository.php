<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\SpielStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Spiel>
 */
class SpielRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Spiel::class);
    }

    public function findLaufendesSpielFuerTisch(Tisch $tisch): ?Spiel
    {
        return $this->createQueryBuilder('s')
            ->where('s.tisch = :tisch')
            ->andWhere('s.status IN (:status)')
            ->setParameter('tisch', $tisch)
            ->setParameter('status', [SpielStatus::VORBEHALT, SpielStatus::LAUFEND, SpielStatus::ARMUT_ANFRAGE, SpielStatus::ARMUT_TAUSCH])
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLetztesBeendetesSpielFuerTisch(Tisch $tisch): ?Spiel
    {
        return $this->createQueryBuilder('s')
            ->where('s.tisch = :tisch')
            ->andWhere('s.status = :status')
            ->setParameter('tisch', $tisch)
            ->setParameter('status', SpielStatus::BEENDET)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Beendete Spiele, an denen der Nutzer als Spieler teilgenommen hat —
     * neueste zuerst. Grundlage der Replay-Liste.
     *
     * @return Spiel[]
     */
    public function findeBeendeteFuerUser(User $user, int $limit = 30): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.teilnehmer', 't')
            ->where('t.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', SpielStatus::BEENDET)
            ->orderBy('s.beendetAm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Spiele, deren verzögerter Abschluss fällig ist (Endstich wurde gezeigt,
     * jetzt Wertung durchführen).
     *
     * @return Spiel[]
     */
    public function findMitFaelligemAbschluss(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.abschlussFaelligAm IS NOT NULL')
            ->andWhere('s.abschlussFaelligAm <= :jetzt')
            ->setParameter('status', SpielStatus::LAUFEND)
            ->setParameter('jetzt', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Alle laufenden Spiele, in denen der aktuelle Spieler seit mindestens $sekunden
     * nicht gezogen hat (für Bot-Timeout-Erkennung).
     *
     * @return Spiel[]
     */
    public function findMitZugTimeout(int $sekunden): array
    {
        $schwelle = new \DateTimeImmutable("-{$sekunden} seconds");

        return $this->createQueryBuilder('s')
            ->where('s.status IN (:status)')
            ->andWhere('s.aktuellerZugBegannAm IS NOT NULL')
            ->andWhere('s.aktuellerZugBegannAm < :schwelle')
            ->setParameter('status', [SpielStatus::VORBEHALT, SpielStatus::LAUFEND, SpielStatus::ARMUT_ANFRAGE, SpielStatus::ARMUT_TAUSCH])
            ->setParameter('schwelle', $schwelle)
            ->getQuery()
            ->getResult();
    }
}
