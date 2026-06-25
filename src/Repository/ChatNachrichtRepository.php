<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatNachricht;
use App\Entity\Tisch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatNachricht>
 */
class ChatNachrichtRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatNachricht::class);
    }

    /**
     * Die letzten Nachrichten eines Tisches in chronologischer Reihenfolge
     * (älteste zuerst), begrenzt auf $limit.
     *
     * @return ChatNachricht[]
     */
    public function findeLetzteFuerTisch(Tisch $tisch, int $limit = 50): array
    {
        $neueste = $this->createQueryBuilder('c')
            ->where('c.tisch = :tisch')
            ->setParameter('tisch', $tisch)
            ->orderBy('c.erstelltAm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($neueste);
    }
}
