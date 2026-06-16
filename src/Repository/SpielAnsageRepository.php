<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Spiel;
use App\Entity\SpielAnsage;
use App\Enum\AnsageTyp;
use App\Enum\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpielAnsage>
 */
class SpielAnsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpielAnsage::class);
    }

    /** Alle Ansagen dieses Spiels, chronologisch. */
    public function findFuerSpiel(Spiel $spiel): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.spiel = :spiel')
            ->setParameter('spiel', $spiel)
            ->orderBy('a.gemachtAm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ansagen eines bestimmten Teams (per Sitzplatz-Liste).
     * @param int[] $sitzplaetze
     */
    public function findFuerTeam(Spiel $spiel, array $sitzplaetze): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.spiel = :spiel')
            ->andWhere('a.sitzplatz IN (:plaetze)')
            ->setParameter('spiel', $spiel)
            ->setParameter('plaetze', $sitzplaetze)
            ->orderBy('a.gemachtAm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hatBereitsAngesagt(Spiel $spiel, int $sitzplatz, AnsageTyp $typ): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.spiel = :spiel')
            ->andWhere('a.sitzplatz = :sitzplatz')
            ->andWhere('a.ansageTyp = :typ')
            ->setParameter('spiel', $spiel)
            ->setParameter('sitzplatz', $sitzplatz)
            ->setParameter('typ', $typ)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Prüft ob das angegebene Team bereits eine Ansage des Typs gemacht hat.
     * @param int[] $teamSitzplaetze
     */
    public function teamHatAngesagt(Spiel $spiel, array $teamSitzplaetze, AnsageTyp $typ): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.spiel = :spiel')
            ->andWhere('a.sitzplatz IN (:plaetze)')
            ->andWhere('a.ansageTyp = :typ')
            ->setParameter('spiel', $spiel)
            ->setParameter('plaetze', $teamSitzplaetze)
            ->setParameter('typ', $typ)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
