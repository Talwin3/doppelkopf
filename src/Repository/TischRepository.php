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
     * Tische, die seit mindestens $minuten ohne Menschen sind.
     *
     * @return Tisch[]
     */
    public function findMenschenloseFuerLoeschung(int $minuten): array
    {
        $schwelle = new \DateTimeImmutable("-{$minuten} minutes");

        return $this->createQueryBuilder('t')
            ->where('t.menschenloseSeitAm IS NOT NULL')
            ->andWhere('t.menschenloseSeitAm <= :schwelle')
            ->setParameter('schwelle', $schwelle)
            ->getQuery()
            ->getResult();
    }
}
