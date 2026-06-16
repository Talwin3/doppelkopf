<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SpielerZugangsListe;
use App\Entity\User;
use App\Enum\ZugangsListenTyp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpielerZugangsListe>
 */
class SpielerZugangsListeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpielerZugangsListe::class);
    }

    /** Prüft, ob $ziel auf der Blacklist von $inhaber steht. */
    public function istAufBlacklist(User $inhaber, User $ziel): bool
    {
        return $this->createQueryBuilder('z')
            ->select('COUNT(z.id)')
            ->where('z.inhaber = :inhaber')
            ->andWhere('z.ziel = :ziel')
            ->andWhere('z.typ = :typ')
            ->setParameter('inhaber', $inhaber)
            ->setParameter('ziel', $ziel)
            ->setParameter('typ', ZugangsListenTyp::BLACKLIST)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Prüft, ob $ziel auf der Whitelist von $inhaber steht. */
    public function istAufWhitelist(User $inhaber, User $ziel): bool
    {
        return $this->createQueryBuilder('z')
            ->select('COUNT(z.id)')
            ->where('z.inhaber = :inhaber')
            ->andWhere('z.ziel = :ziel')
            ->andWhere('z.typ = :typ')
            ->setParameter('inhaber', $inhaber)
            ->setParameter('ziel', $ziel)
            ->setParameter('typ', ZugangsListenTyp::WHITELIST)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Alle Blacklist-Einträge eines Inhabers. */
    public function findBlacklist(User $inhaber): array
    {
        return $this->createQueryBuilder('z')
            ->where('z.inhaber = :inhaber')
            ->andWhere('z.typ = :typ')
            ->setParameter('inhaber', $inhaber)
            ->setParameter('typ', ZugangsListenTyp::BLACKLIST)
            ->getQuery()
            ->getResult();
    }

    /** Alle Whitelist-Einträge eines Inhabers. */
    public function findWhitelist(User $inhaber): array
    {
        return $this->createQueryBuilder('z')
            ->where('z.inhaber = :inhaber')
            ->andWhere('z.typ = :typ')
            ->setParameter('inhaber', $inhaber)
            ->setParameter('typ', ZugangsListenTyp::WHITELIST)
            ->getQuery()
            ->getResult();
    }
}
