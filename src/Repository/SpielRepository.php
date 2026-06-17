<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Spiel;
use App\Entity\Tisch;
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
            ->setParameter('status', [SpielStatus::VORBEHALT, SpielStatus::LAUFEND])
            ->getQuery()
            ->getOneOrNullResult();
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
            ->setParameter('status', [SpielStatus::VORBEHALT, SpielStatus::LAUFEND])
            ->setParameter('schwelle', $schwelle)
            ->getQuery()
            ->getResult();
    }
}
