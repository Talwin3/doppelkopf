<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatNachrichtRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Eine Chat-Nachricht an einem Tisch. Tischgebunden und über Spielgrenzen hinweg
 * persistent. Der Absendername wird als Schnappschuss gespeichert, damit die
 * Nachricht lesbar bleibt, wenn der Nutzer den Tisch verlässt oder gelöscht wird.
 */
#[ORM\Entity(repositoryClass: ChatNachrichtRepository::class)]
#[ORM\Table(name: 'chat_nachrichten')]
#[ORM\Index(name: 'idx_chat_tisch_zeit', columns: ['tisch_id', 'erstellt_am'])]
class ChatNachricht
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tisch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tisch $tisch;

    /** Null, wenn der Nutzer später gelöscht wurde (Name bleibt über absenderName erhalten). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $absender = null;

    #[ORM\Column(length: 50)]
    private string $absenderName;

    #[ORM\Column(length: 500)]
    private string $text;

    #[ORM\Column]
    private \DateTimeImmutable $erstelltAm;

    public function __construct()
    {
        $this->id         = Uuid::v7();
        $this->erstelltAm = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getTisch(): Tisch { return $this->tisch; }
    public function setTisch(Tisch $tisch): static { $this->tisch = $tisch; return $this; }

    public function getAbsender(): ?User { return $this->absender; }
    public function setAbsender(?User $absender): static { $this->absender = $absender; return $this; }

    public function getAbsenderName(): string { return $this->absenderName; }
    public function setAbsenderName(string $name): static { $this->absenderName = $name; return $this; }

    public function getText(): string { return $this->text; }
    public function setText(string $text): static { $this->text = $text; return $this; }

    public function getErstelltAm(): \DateTimeImmutable { return $this->erstelltAm; }
}
