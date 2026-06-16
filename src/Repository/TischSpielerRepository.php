<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TischSpieler>
 */
class TischSpielerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TischSpieler::class);
    }

    /** Prüft, ob ein User bereits an diesem Tisch sitzt oder wartet. */
    public function findByTischAndUser(Tisch $tisch, User $user): ?TischSpieler
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.tisch = :tisch')
            ->andWhere('ts.user = :user')
            ->setParameter('tisch', $tisch)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Nächste freie Sitzplatznummer (1–4), oder null wenn voll. */
    public function naechstesFreiesSitzplatz(Tisch $tisch): ?int
    {
        $belegt = $this->createQueryBuilder('ts')
            ->select('ts.sitzplatz')
            ->where('ts.tisch = :tisch')
            ->andWhere('ts.sitzplatz IS NOT NULL')
            ->setParameter('tisch', $tisch)
            ->getQuery()
            ->getSingleColumnResult();

        foreach (range(1, 4) as $platz) {
            if (!in_array($platz, $belegt, true)) {
                return $platz;
            }
        }

        return null;
    }

    /** Maximale Warteschlangenposition am Tisch (für Anhängen ans Ende). */
    public function maxWarteschlangenPosition(Tisch $tisch): int
    {
        return (int) $this->createQueryBuilder('ts')
            ->select('MAX(ts.positionInWarteschlange)')
            ->where('ts.tisch = :tisch')
            ->andWhere('ts.positionInWarteschlange IS NOT NULL')
            ->setParameter('tisch', $tisch)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
