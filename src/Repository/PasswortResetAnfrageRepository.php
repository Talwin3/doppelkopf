<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswortResetAnfrage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * Persistenz für {@see PasswortResetAnfrage}. Den Großteil der geforderten
 * Methoden liefert der Trait des Bundles; nur das Anlegen kennt unsere Entity.
 *
 * @extends ServiceEntityRepository<PasswortResetAnfrage>
 */
class PasswortResetAnfrageRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswortResetAnfrage::class);
    }

    public function createResetPasswordRequest(
        object             $user,
        \DateTimeInterface $expiresAt,
        string             $selector,
        string             $hashedToken,
    ): ResetPasswordRequestInterface {
        \assert($user instanceof User);

        return new PasswortResetAnfrage($user, $expiresAt, $selector, $hashedToken);
    }
}
