<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Repository\GespielteKarteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GespielteKarteRepository::class)]
#[ORM\Table(name: 'gespielte_karten')]
#[ORM\UniqueConstraint(name: 'uq_stich_position', columns: ['spiel_id', 'stich_nr', 'position_im_stich'])]
#[ORM\UniqueConstraint(name: 'uq_karte_einmal_gespielt', columns: ['spiel_id', 'sitzplatz', 'karte_id'])]
class GespielteKarte
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Spiel::class, inversedBy: 'gespielteKarten')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Spiel $spiel;

    #[ORM\Column]
    private int $sitzplatz; // 1–4

    #[ORM\Column]
    private int $stichNr; // 1–12

    #[ORM\Column]
    private int $positionImStich; // 1–4

    /** Z.B. "KREUZ_DAME_1" */
    #[ORM\Column(length: 30)]
    private string $karteId;

    #[ORM\Column]
    private \DateTimeImmutable $gespieltAm;

    public function __construct()
    {
        $this->id         = Uuid::v7();
        $this->gespieltAm = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSpiel(): Spiel { return $this->spiel; }
    public function setSpiel(Spiel $spiel): static { $this->spiel = $spiel; return $this; }

    public function getSitzplatz(): int { return $this->sitzplatz; }
    public function setSitzplatz(int $sitzplatz): static { $this->sitzplatz = $sitzplatz; return $this; }

    public function getStichNr(): int { return $this->stichNr; }
    public function setStichNr(int $nr): static { $this->stichNr = $nr; return $this; }

    public function getPositionImStich(): int { return $this->positionImStich; }
    public function setPositionImStich(int $pos): static { $this->positionImStich = $pos; return $this; }

    public function getKarteId(): string { return $this->karteId; }
    public function setKarteId(string $id): static { $this->karteId = $id; return $this; }

    public function getGespieltAm(): \DateTimeImmutable { return $this->gespieltAm; }

    public function alsKarte(): Karte
    {
        return Karte::vonId($this->karteId);
    }
}
