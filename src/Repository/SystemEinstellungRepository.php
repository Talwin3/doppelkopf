<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SystemEinstellung;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemEinstellung>
 */
class SystemEinstellungRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemEinstellung::class);
    }

    public function findBySchluessel(string $schluessel): ?SystemEinstellung
    {
        return $this->findOneBy(['schluessel' => $schluessel]);
    }

    /** @return SystemEinstellung[] */
    public function findAlle(): array
    {
        return $this->findBy([], ['schluessel' => 'ASC']);
    }
}
