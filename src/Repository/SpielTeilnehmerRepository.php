<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpielTeilnehmer>
 */
class SpielTeilnehmerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpielTeilnehmer::class);
    }

    public function findBySpielAndUser(Spiel $spiel, User $user): ?SpielTeilnehmer
    {
        return $this->createQueryBuilder('st')
            ->where('st.spiel = :spiel')
            ->andWhere('st.user = :user')
            ->setParameter('spiel', $spiel)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
