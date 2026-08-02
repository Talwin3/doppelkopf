<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswortResetAnfrageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

/**
 * Eine angeforderte Passwort-Zurücksetzung. Das Bundle speichert hier nur den
 * öffentlichen Selektor im Klartext; der eigentliche Token liegt ausschließlich
 * gehasht (`hashedToken`) in der Datenbank und im Link der E-Mail. Ein Leak der
 * Tabelle erlaubt also kein Zurücksetzen.
 *
 * Wird der Nutzer gelöscht, verschwinden seine offenen Anfragen mit (CASCADE).
 * Abgelaufene Einträge räumt die Garbage Collection des Bundles auf.
 */
#[ORM\Entity(repositoryClass: PasswortResetAnfrageRepository::class)]
#[ORM\Table(name: 'passwort_reset_anfragen')]
#[ORM\Index(name: 'idx_reset_selector', columns: ['selector'])]
class PasswortResetAnfrage implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function __construct(User $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        $this->id   = Uuid::v7();
        $this->user = $user;

        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
