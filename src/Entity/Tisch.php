<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TischStatus;
use App\Enum\ZugangsModusTyp;
use App\Repository\TischRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TischRepository::class)]
#[ORM\Table(name: 'tische')]
#[ORM\HasLifecycleCallbacks]
class Tisch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $ersteller;

    #[ORM\Column(length: 20, enumType: TischStatus::class)]
    private TischStatus $status = TischStatus::WARTEND;

    #[ORM\Column(length: 10, enumType: ZugangsModusTyp::class)]
    private ZugangsModusTyp $zugangsmodus = ZugangsModusTyp::OFFEN;

    #[ORM\Column(options: ['default' => false])]
    private bool $istGesperrt = false;

    #[ORM\Column]
    private \DateTimeImmutable $erstelltAm;

    #[ORM\Column]
    private \DateTimeImmutable $aktualisiertAm;

    /** @var Collection<int, TischSpieler> */
    #[ORM\OneToMany(targetEntity: TischSpieler::class, mappedBy: 'tisch', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['beigetretenAm' => 'ASC'])]
    private Collection $spieler;

    public function __construct()
    {
        $this->id           = Uuid::v7();
        $this->erstelltAm   = new \DateTimeImmutable();
        $this->aktualisiertAm = new \DateTimeImmutable();
        $this->spieler      = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function aktualisiertAmAktualisieren(): void
    {
        $this->aktualisiertAm = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getErsteller(): User
    {
        return $this->ersteller;
    }

    public function setErsteller(User $ersteller): static
    {
        $this->ersteller = $ersteller;

        return $this;
    }

    public function getStatus(): TischStatus
    {
        return $this->status;
    }

    public function setStatus(TischStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getZugangsmodus(): ZugangsModusTyp
    {
        return $this->zugangsmodus;
    }

    public function setZugangsmodus(ZugangsModusTyp $zugangsmodus): static
    {
        $this->zugangsmodus = $zugangsmodus;

        return $this;
    }

    public function isIstGesperrt(): bool
    {
        return $this->istGesperrt;
    }

    public function setIstGesperrt(bool $istGesperrt): static
    {
        $this->istGesperrt = $istGesperrt;

        return $this;
    }

    public function getErstelltAm(): \DateTimeImmutable
    {
        return $this->erstelltAm;
    }

    public function getAktualisiertAm(): \DateTimeImmutable
    {
        return $this->aktualisiertAm;
    }

    /** @return Collection<int, TischSpieler> */
    public function getSpieler(): Collection
    {
        return $this->spieler;
    }

    /** Aktive Spieler an Sitzplätzen 1–4. */
    public function getAktiveSpieler(): Collection
    {
        return $this->spieler->filter(fn(TischSpieler $ts) => $ts->getSitzplatz() !== null);
    }

    /** Spieler in der Rotation-Warteschlange (noch kein Sitzplatz). */
    public function getWarteschlange(): Collection
    {
        return $this->spieler->filter(fn(TischSpieler $ts) => $ts->getSitzplatz() === null);
    }

    public function anzahlAktiveSpieler(): int
    {
        return $this->getAktiveSpieler()->count();
    }
}
