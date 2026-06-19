<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AnsageTyp;
use App\Repository\SpielAnsageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SpielAnsageRepository::class)]
#[ORM\Table(name: 'spiel_ansagen')]
#[ORM\UniqueConstraint(name: 'uq_ansage_typ_sitzplatz', columns: ['spiel_id', 'sitzplatz', 'ansage_typ'])]
class SpielAnsage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Spiel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Spiel $spiel;

    #[ORM\Column]
    private int $sitzplatz;

    #[ORM\Column(length: 15, enumType: AnsageTyp::class)]
    private AnsageTyp $ansageTyp;

    /** Stich-Nr zum Zeitpunkt der Ansage (für Replay). */
    #[ORM\Column]
    private int $stichNrBeiAnsage;

    /** Karten noch in der Hand zum Zeitpunkt der Ansage (für Timing-Nachweis). */
    #[ORM\Column]
    private int $kartenNochInHand;

    #[ORM\Column]
    private \DateTimeImmutable $gemachtAm;

    public function __construct()
    {
        $this->id         = Uuid::v7();
        $this->gemachtAm  = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSpiel(): Spiel { return $this->spiel; }
    public function setSpiel(Spiel $spiel): static { $this->spiel = $spiel; return $this; }

    public function getSitzplatz(): int { return $this->sitzplatz; }
    public function setSitzplatz(int $sitzplatz): static { $this->sitzplatz = $sitzplatz; return $this; }

    public function getAnsageTyp(): AnsageTyp { return $this->ansageTyp; }
    public function setAnsageTyp(AnsageTyp $typ): static { $this->ansageTyp = $typ; return $this; }

    public function getStichNrBeiAnsage(): int { return $this->stichNrBeiAnsage; }
    public function setStichNrBeiAnsage(int $nr): static { $this->stichNrBeiAnsage = $nr; return $this; }

    public function getKartenNochInHand(): int { return $this->kartenNochInHand; }
    public function setKartenNochInHand(int $n): static { $this->kartenNochInHand = $n; return $this; }

    public function getGemachtAm(): \DateTimeImmutable { return $this->gemachtAm; }
}
