<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tisch;
use App\Enum\TischStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tisch>
 */
class TischRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tisch::class);
    }

    /** Alle nicht-beendeten Tische für die Lobby-Übersicht. */
    public function findAktiveFuerLobby(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status != :beendet')
            ->setParameter('beendet', TischStatus::BEENDET)
            ->orderBy('t.erstelltAm', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
