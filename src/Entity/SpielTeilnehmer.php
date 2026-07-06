<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\BotStaerke;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\VorbehaltTyp;
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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
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

    /** Zufälliger Anzeigename für Bot-Teilnehmer (null bei menschlichen Spielern). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $botName = null;

    /** Spielstärke des Bots (nur relevant wenn istBot); bei Menschen bedeutungslos. */
    #[ORM\Column(length: 20, enumType: BotStaerke::class, options: ['default' => 'anfaenger'])]
    private BotStaerke $botStaerke = BotStaerke::ANFAENGER;

    /** Hat dieser Spieler seinen Vorbehalt bereits deklariert? */
    #[ORM\Column(options: ['default' => false])]
    private bool $vorbehaltDeklariert = false;

    #[ORM\Column(length: 10, enumType: VorbehaltTyp::class, nullable: true)]
    private ?VorbehaltTyp $vorbehaltTyp = null;

    /** Nur relevant wenn vorbehaltTyp = SOLO. */
    #[ORM\Column(length: 20, enumType: SpielVariante::class, nullable: true)]
    private ?SpielVariante $vorbehaltSoloVariante = null;

    /** Armut-Antwort: null = noch nicht gefragt, false = abgelehnt, true = angenommen. */
    #[ORM\Column(nullable: true)]
    private ?bool $armutAntwort = null;

    /**
     * True, solange dieser (menschliche) Spieler nach einem Zug-Timeout von einem
     * Bot vertreten wird. Verhindert, dass die Übernahme bei jedem Folgezug erneut
     * ins Event-Log geschrieben wird; wird zurückgesetzt, sobald der Spieler wieder
     * selbst handelt.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $vonBotVertreten = false;

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

    public function getBotName(): ?string { return $this->botName; }
    public function setBotName(?string $botName): static { $this->botName = $botName; return $this; }

    public function getBotStaerke(): BotStaerke { return $this->botStaerke; }
    public function setBotStaerke(BotStaerke $botStaerke): static { $this->botStaerke = $botStaerke; return $this; }

    /**
     * Öffentlicher Anzeigename: bei Menschen der Username, bei Bots der
     * zufällige Vorname mit Suffix "(Bot)", damit Bots erkennbar bleiben.
     */
    public function getAnzeigeName(): string
    {
        if ($this->user !== null) {
            return $this->user->getUsername();
        }

        return $this->botName !== null ? $this->botName . ' (Bot)' : 'Bot';
    }

    public function isVorbehaltDeklariert(): bool { return $this->vorbehaltDeklariert; }
    public function setVorbehaltDeklariert(bool $deklariert): static { $this->vorbehaltDeklariert = $deklariert; return $this; }

    public function getVorbehaltTyp(): ?VorbehaltTyp { return $this->vorbehaltTyp; }
    public function setVorbehaltTyp(?VorbehaltTyp $typ): static { $this->vorbehaltTyp = $typ; return $this; }

    public function getVorbehaltSoloVariante(): ?SpielVariante { return $this->vorbehaltSoloVariante; }
    public function setVorbehaltSoloVariante(?SpielVariante $variante): static { $this->vorbehaltSoloVariante = $variante; return $this; }

    public function isGewonnen(): ?bool { return $this->gewonnen; }
    public function setGewonnen(?bool $gewonnen): static { $this->gewonnen = $gewonnen; return $this; }

    public function getPunkteDelta(): ?int { return $this->punkteDelta; }
    public function setPunkteDelta(?int $delta): static { $this->punkteDelta = $delta; return $this; }

    public function isVonBotVertreten(): bool { return $this->vonBotVertreten; }
    public function setVonBotVertreten(bool $vertreten): static { $this->vonBotVertreten = $vertreten; return $this; }

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

    public function getArmutAntwort(): ?bool { return $this->armutAntwort; }
    public function setArmutAntwort(?bool $antwort): static { $this->armutAntwort = $antwort; return $this; }

    /** Zählt Kreuz-Damen in der Starthand (für Hochzeit-Validierung). */
    public function anzahlKreuzDamen(): int
    {
        $anzahl = 0;
        foreach ($this->startkartenIds as $id) {
            $karte = Karte::vonId($id);
            if ($karte->farbe === Kartenfarbe::KREUZ && $karte->wert === Kartenwert::DAME) {
                $anzahl++;
            }
        }
        return $anzahl;
    }
}
