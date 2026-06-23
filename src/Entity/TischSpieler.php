<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TischSpielerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TischSpielerRepository::class)]
#[ORM\Table(name: 'tisch_spieler')]
#[ORM\UniqueConstraint(name: 'uq_tisch_sitzplatz', columns: ['tisch_id', 'sitzplatz'])]
#[ORM\UniqueConstraint(name: 'uq_tisch_user', columns: ['tisch_id', 'user_id'])]
class TischSpieler
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tisch::class, inversedBy: 'spieler')]
    #[ORM\JoinColumn(nullable: false)]
    private Tisch $tisch;

    /**
     * Null bei Bot-Platzhaltern.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    /**
     * Sitzplatz 1–4 am Tisch. Null = in der Rotation-Warteschlange.
     */
    #[ORM\Column(nullable: true)]
    private ?int $sitzplatz = null;

    /**
     * Reihenfolge in der Warteschlange für die nächste Rotation.
     * Null wenn aktiver Spieler (sitzplatz gesetzt).
     */
    #[ORM\Column(nullable: true)]
    private ?int $positionInWarteschlange = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $istBot = false;

    /** Zufälliger Anzeigename für Bot-Platzhalter (null bei menschlichen Spielern). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $botName = null;

    /** Spieler hat gewünscht, nach dem laufenden Spiel den Tisch zu verlassen. */
    #[ORM\Column(options: ['default' => false])]
    private bool $moechteNachSpielVerlassen = false;

    /** Zeitpunkt des letzten bekannten Verbindungsverlustes (für Disconnect-Handling). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disconnectSeitAm = null;

    #[ORM\Column]
    private \DateTimeImmutable $beigetretenAm;

    public function __construct()
    {
        $this->id           = Uuid::v7();
        $this->beigetretenAm = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTisch(): Tisch
    {
        return $this->tisch;
    }

    public function setTisch(Tisch $tisch): static
    {
        $this->tisch = $tisch;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSitzplatz(): ?int
    {
        return $this->sitzplatz;
    }

    public function setSitzplatz(?int $sitzplatz): static
    {
        $this->sitzplatz = $sitzplatz;

        return $this;
    }

    public function getPositionInWarteschlange(): ?int
    {
        return $this->positionInWarteschlange;
    }

    public function setPositionInWarteschlange(?int $position): static
    {
        $this->positionInWarteschlange = $position;

        return $this;
    }

    public function isIstBot(): bool
    {
        return $this->istBot;
    }

    public function setIstBot(bool $istBot): static
    {
        $this->istBot = $istBot;

        return $this;
    }

    public function getBotName(): ?string
    {
        return $this->botName;
    }

    public function setBotName(?string $botName): static
    {
        $this->botName = $botName;

        return $this;
    }

    /**
     * Öffentlicher Anzeigename am Tisch: bei Menschen der Username, bei Bots
     * der zufällige Vorname mit Suffix "(Bot)", damit Bots erkennbar bleiben.
     */
    public function getAnzeigeName(): string
    {
        if ($this->user !== null) {
            return $this->user->getUsername();
        }

        return $this->botName !== null ? $this->botName . ' (Bot)' : 'Bot';
    }

    public function getBeigetretenAm(): \DateTimeImmutable
    {
        return $this->beigetretenAm;
    }

    public function isMoechteNachSpielVerlassen(): bool { return $this->moechteNachSpielVerlassen; }
    public function setMoechteNachSpielVerlassen(bool $moechte): static { $this->moechteNachSpielVerlassen = $moechte; return $this; }

    public function getDisconnectSeitAm(): ?\DateTimeImmutable { return $this->disconnectSeitAm; }
    public function setDisconnectSeitAm(?\DateTimeImmutable $am): static { $this->disconnectSeitAm = $am; return $this; }

    public function istAktiv(): bool
    {
        return $this->sitzplatz !== null;
    }

    public function istInWarteschlange(): bool
    {
        return $this->sitzplatz === null;
    }
}
