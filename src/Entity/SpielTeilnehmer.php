<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;
use App\Repository\SpielTeilnehmerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SpielTeilnehmerRepository::class)]
#[ORM\Table(name: 'spiel_teilnehmer')]
#[ORM\UniqueConstraint(name: 'uq_spiel_sitzplatz', columns: ['spiel_id', 'sitzplatz'])]
class SpielTeilnehmer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Spiel::class, inversedBy: 'teilnehmer')]
    #[ORM\JoinColumn(nullable: false)]
    private Spiel $spiel;

    /** Null bei Bot-Platzhaltern. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\Column]
    private int $sitzplatz; // 1–4

    /**
     * Die 12 Startkarten als Array von Karten-IDs (unveränderlich nach Ausgabe).
     * Format: ["KREUZ_DAME_1", "KARO_NEUN_2", ...]
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $startkartenIds = [];

    #[ORM\Column(length: 10, enumType: Team::class, nullable: true)]
    private ?Team $team = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $istBot = false;

    #[ORM\Column(nullable: true)]
    private ?bool $gewonnen = null;

    #[ORM\Column(nullable: true)]
    private ?int $punkteDelta = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSpiel(): Spiel { return $this->spiel; }
    public function setSpiel(Spiel $spiel): static { $this->spiel = $spiel; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getSitzplatz(): int { return $this->sitzplatz; }
    public function setSitzplatz(int $sitzplatz): static { $this->sitzplatz = $sitzplatz; return $this; }

    /** @return string[] */
    public function getStartkartenIds(): array { return $this->startkartenIds; }

    /** @param string[] $ids */
    public function setStartkartenIds(array $ids): static { $this->startkartenIds = $ids; return $this; }

    public function getTeam(): ?Team { return $this->team; }
    public function setTeam(?Team $team): static { $this->team = $team; return $this; }

    public function isIstBot(): bool { return $this->istBot; }
    public function setIstBot(bool $istBot): static { $this->istBot = $istBot; return $this; }

    public function isGewonnen(): ?bool { return $this->gewonnen; }
    public function setGewonnen(?bool $gewonnen): static { $this->gewonnen = $gewonnen; return $this; }

    public function getPunkteDelta(): ?int { return $this->punkteDelta; }
    public function setPunkteDelta(?int $delta): static { $this->punkteDelta = $delta; return $this; }

    /** Aktuelle Hand: Startkarten minus bereits gespielte. */
    public function aktuelleHand(array $gespielteKartenIds): array
    {
        $gespielteIds = array_flip($gespielteKartenIds);
        $hand = [];
        foreach ($this->startkartenIds as $id) {
            if (!isset($gespielteIds[$id])) {
                $hand[] = Karte::vonId($id);
            }
        }
        return $hand;
    }
}
