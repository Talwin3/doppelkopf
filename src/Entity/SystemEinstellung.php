<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SystemEinstellungRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SystemEinstellungRepository::class)]
#[ORM\Table(name: 'system_einstellungen')]
#[ORM\HasLifecycleCallbacks]
class SystemEinstellung
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $schluessel;

    #[ORM\Column(length: 500)]
    private string $wert;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $beschreibung = null;

    #[ORM\Column]
    private \DateTimeImmutable $aktualisiertAm;

    public function __construct(string $schluessel, string $wert, ?string $beschreibung = null)
    {
        $this->schluessel     = $schluessel;
        $this->wert           = $wert;
        $this->beschreibung   = $beschreibung;
        $this->aktualisiertAm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aktualisiertAmAktualisieren(): void
    {
        $this->aktualisiertAm = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSchluessel(): string { return $this->schluessel; }

    public function getWert(): string { return $this->wert; }
    public function setWert(string $wert): static { $this->wert = $wert; return $this; }

    public function getBeschreibung(): ?string { return $this->beschreibung; }
    public function setBeschreibung(?string $beschreibung): static { $this->beschreibung = $beschreibung; return $this; }

    public function getAktualisiertAm(): \DateTimeImmutable { return $this->aktualisiertAm; }

    public function alsInt(): int { return (int) $this->wert; }
    public function alsBool(): bool { return in_array($this->wert, ['1', 'true', 'yes'], true); }
}
