<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GespielteKarte;
use App\Entity\Spiel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GespielteKarte>
 */
class GespielteKarteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GespielteKarte::class);
    }

    /** Alle Karten-IDs, die ein Spieler (Sitzplatz) in diesem Spiel gespielt hat. */
    public function findGespielteKartenIds(Spiel $spiel, int $sitzplatz): array
    {
        return $this->createQueryBuilder('gk')
            ->select('gk.karteId')
            ->where('gk.spiel = :spiel')
            ->andWhere('gk.sitzplatz = :sitzplatz')
            ->setParameter('spiel', $spiel)
            ->setParameter('sitzplatz', $sitzplatz)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Die 4 Karten des aktuellen Stichs, geordnet nach Position. */
    public function findAktuellerStich(Spiel $spiel, int $stichNr): array
    {
        return $this->createQueryBuilder('gk')
            ->where('gk.spiel = :spiel')
            ->andWhere('gk.stichNr = :stichNr')
            ->setParameter('spiel', $spiel)
            ->setParameter('stichNr', $stichNr)
            ->orderBy('gk.positionImStich', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Alle Karten eines abgeschlossenen Stichs (für Augen-Zählung). */
    public function findStich(Spiel $spiel, int $stichNr): array
    {
        return $this->findAktuellerStich($spiel, $stichNr);
    }

    /** Alle gespielten Karten dieses Spiels. */
    public function findAlleGespieltenKarten(Spiel $spiel): array
    {
        return $this->createQueryBuilder('gk')
            ->where('gk.spiel = :spiel')
            ->setParameter('spiel', $spiel)
            ->orderBy('gk.stichNr', 'ASC')
            ->addOrderBy('gk.positionImStich', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
