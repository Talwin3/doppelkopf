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

    /** Anzahl der nicht-beendeten Tische – dieselbe Abgrenzung wie in der Lobby. */
    public function zaehleAktive(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status != :beendet')
            ->setParameter('beendet', TischStatus::BEENDET)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Tische mit abgelaufenem Auto-Start-Countdown (naechsterSpielstartAm <= now).
     *
     * @return Tisch[]
     */
    public function findMitFaelligemAutoStart(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.naechsterSpielstartAm IS NOT NULL')
            ->andWhere('t.naechsterSpielstartAm <= :jetzt')
            ->setParameter('jetzt', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Tische ohne menschliche Spieler (nur Bots).
     *
     * @return Tisch[]
     */
    public function findMenschenlose(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.menschenloseSeitAm IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
