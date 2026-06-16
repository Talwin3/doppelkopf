<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ZugangsListenTyp;
use App\Repository\SpielerZugangsListeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SpielerZugangsListeRepository::class)]
#[ORM\Table(name: 'spieler_zugangsliste')]
#[ORM\UniqueConstraint(name: 'uq_zugangsliste_eintrag', columns: ['inhaber_id', 'ziel_id', 'typ'])]
class SpielerZugangsListe
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Der Spieler, dem diese Liste gehört.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'inhaber_id', nullable: false)]
    private User $inhaber;

    /**
     * Der Spieler, der eingetragen ist.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'ziel_id', nullable: false)]
    private User $ziel;

    #[ORM\Column(length: 10, enumType: ZugangsListenTyp::class)]
    private ZugangsListenTyp $typ;

    #[ORM\Column]
    private \DateTimeImmutable $erstelltAm;

    public function __construct()
    {
        $this->id         = Uuid::v7();
        $this->erstelltAm = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInhaber(): User
    {
        return $this->inhaber;
    }

    public function setInhaber(User $inhaber): static
    {
        $this->inhaber = $inhaber;

        return $this;
    }

    public function getZiel(): User
    {
        return $this->ziel;
    }

    public function setZiel(User $ziel): static
    {
        $this->ziel = $ziel;

        return $this;
    }

    public function getTyp(): ZugangsListenTyp
    {
        return $this->typ;
    }

    public function setTyp(ZugangsListenTyp $typ): static
    {
        $this->typ = $typ;

        return $this;
    }

    public function getErstelltAm(): \DateTimeImmutable
    {
        return $this->erstelltAm;
    }
}
