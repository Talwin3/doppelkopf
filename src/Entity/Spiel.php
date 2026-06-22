<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Repository\SpielRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SpielRepository::class)]
#[ORM\Table(name: 'spiele')]
class Spiel
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tisch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tisch $tisch;

    /** Null während der Vorbehaltsrunde (wird durch SpielTypResolver gesetzt). */
    #[ORM\Column(length: 20, enumType: SpielVariante::class, nullable: true)]
    private ?SpielVariante $variante = null;

    #[ORM\Column(length: 20, enumType: SpielStatus::class)]
    private SpielStatus $status = SpielStatus::LAUFEND;

    /** Sitzplatz (1–4) des Spielers, der als nächstes eine Karte spielen muss. */
    #[ORM\Column]
    private int $aktuellerSpielerSitzplatz = 1;

    /** Aktuell laufende Stich-Nummer (1–12). */
    #[ORM\Column]
    private int $aktuellerStichNr = 1;

    /** Gesetzt, wenn bei Hochzeit der Partner gefunden wurde. */
    #[ORM\Column(options: ['default' => false])]
    private bool $hochzeitAufgeloest = false;

    /** Sitzplatz des Armut-Spielers (null = keine Armut). */
    #[ORM\Column(nullable: true)]
    private ?int $armutSpielerSitzplatz = null;

    /** Sitzplatz des Spielers, der die Armut angenommen hat (null = noch offen). */
    #[ORM\Column(nullable: true)]
    private ?int $armutAnnehmerSitzplatz = null;

    /** Karten-IDs die der Armut-Spieler abgibt (seine Trümpfe). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $armutTauschKartenIds = null;

    #[ORM\Column]
    private \DateTimeImmutable $gestartetAm;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $beendetAm = null;

    /** Zeitpunkt, ab dem der aktuelle Spieler an der Reihe ist (für Bot-Timeout-Erkennung). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $aktuellerZugBegannAm = null;

    /**
     * Zeitpunkt, ab dem der Worker das Spiel abschließt. Wird nach der letzten
     * Karte gesetzt, damit der Endstich kurz sichtbar bleibt, bevor die Wertung erscheint.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $abschlussFaelligAm = null;

    /**
     * Detaillierte Abrechnung des beendeten Spiels (Augensummen, einzelne Wertungs-Positionen,
     * Sieger, Spielwert). Wird beim Abschluss vom WertungsRechner befüllt. null solange laufend.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $wertungDetails = null;

    /** @var Collection<int, SpielTeilnehmer> */
    #[ORM\OneToMany(targetEntity: SpielTeilnehmer::class, mappedBy: 'spiel', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sitzplatz' => 'ASC'])]
    private Collection $teilnehmer;

    /** @var Collection<int, GespielteKarte> */
    #[ORM\OneToMany(targetEntity: GespielteKarte::class, mappedBy: 'spiel', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['stichNr' => 'ASC', 'positionImStich' => 'ASC'])]
    private Collection $gespielteKarten;

    public function __construct()
    {
        $this->id             = Uuid::v7();
        $this->gestartetAm    = new \DateTimeImmutable();
        $this->teilnehmer     = new ArrayCollection();
        $this->gespielteKarten = new ArrayCollection();
    }

    public function getId(): Uuid { return $this->id; }

    public function getTisch(): Tisch { return $this->tisch; }
    public function setTisch(Tisch $tisch): static { $this->tisch = $tisch; return $this; }

    public function getVariante(): ?SpielVariante { return $this->variante; }
    public function setVariante(?SpielVariante $variante): static { $this->variante = $variante; return $this; }

    public function getStatus(): SpielStatus { return $this->status; }
    public function setStatus(SpielStatus $status): static { $this->status = $status; return $this; }

    public function getAktuellerSpielerSitzplatz(): int { return $this->aktuellerSpielerSitzplatz; }
    public function setAktuellerSpielerSitzplatz(int $sitzplatz): static { $this->aktuellerSpielerSitzplatz = $sitzplatz; return $this; }

    public function getAktuellerStichNr(): int { return $this->aktuellerStichNr; }
    public function setAktuellerStichNr(int $nr): static { $this->aktuellerStichNr = $nr; return $this; }

    public function isHochzeitAufgeloest(): bool { return $this->hochzeitAufgeloest; }
    public function setHochzeitAufgeloest(bool $aufgeloest): static { $this->hochzeitAufgeloest = $aufgeloest; return $this; }

    public function getArmutSpielerSitzplatz(): ?int { return $this->armutSpielerSitzplatz; }
    public function setArmutSpielerSitzplatz(?int $sitzplatz): static { $this->armutSpielerSitzplatz = $sitzplatz; return $this; }

    public function getArmutAnnehmerSitzplatz(): ?int { return $this->armutAnnehmerSitzplatz; }
    public function setArmutAnnehmerSitzplatz(?int $sitzplatz): static { $this->armutAnnehmerSitzplatz = $sitzplatz; return $this; }

    /** @return string[]|null */
    public function getArmutTauschKartenIds(): ?array { return $this->armutTauschKartenIds; }
    /** @param string[]|null $ids */
    public function setArmutTauschKartenIds(?array $ids): static { $this->armutTauschKartenIds = $ids; return $this; }

    public function getGestartetAm(): \DateTimeImmutable { return $this->gestartetAm; }

    public function getBeendetAm(): ?\DateTimeImmutable { return $this->beendetAm; }
    public function setBeendetAm(?\DateTimeImmutable $am): static { $this->beendetAm = $am; return $this; }

    /** @return array<string, mixed>|null */
    public function getWertungDetails(): ?array { return $this->wertungDetails; }
    /** @param array<string, mixed>|null $details */
    public function setWertungDetails(?array $details): static { $this->wertungDetails = $details; return $this; }

    public function getAktuellerZugBegannAm(): ?\DateTimeImmutable { return $this->aktuellerZugBegannAm; }
    public function setAktuellerZugBegannAm(?\DateTimeImmutable $am): static { $this->aktuellerZugBegannAm = $am; return $this; }

    public function getAbschlussFaelligAm(): ?\DateTimeImmutable { return $this->abschlussFaelligAm; }
    public function setAbschlussFaelligAm(?\DateTimeImmutable $am): static { $this->abschlussFaelligAm = $am; return $this; }

    /** @return Collection<int, SpielTeilnehmer> */
    public function getTeilnehmer(): Collection { return $this->teilnehmer; }

    /** @return Collection<int, GespielteKarte> */
    public function getGespielteKarten(): Collection { return $this->gespielteKarten; }

    public function istBeendet(): bool { return $this->status === SpielStatus::BEENDET; }

    public function getTeilnehmerBySitzplatz(int $sitzplatz): ?SpielTeilnehmer
    {
        foreach ($this->teilnehmer as $t) {
            if ($t->getSitzplatz() === $sitzplatz) {
                return $t;
            }
        }
        return null;
    }

    /** Karten des aktuellen Stichs in Spielreihenfolge. */
    public function getAktuelleStichKarten(): Collection
    {
        $nr = $this->aktuellerStichNr;
        return $this->gespielteKarten->filter(fn(GespielteKarte $gk) => $gk->getStichNr() === $nr);
    }

    /**
     * Karten für die Tischmitte: der aktuelle Stich – oder, solange dieser noch
     * leer ist, der gerade abgeschlossene vorherige Stich. So bleibt die vierte
     * Karte sichtbar, bis zum nächsten Stich angespielt wird.
     */
    public function getAnzuzeigendeStichKarten(): Collection
    {
        $aktuelle = $this->getAktuelleStichKarten();
        if (!$aktuelle->isEmpty() || $this->aktuellerStichNr <= 1) {
            return $aktuelle;
        }

        $vorherigeNr = $this->aktuellerStichNr - 1;
        return $this->gespielteKarten->filter(fn(GespielteKarte $gk) => $gk->getStichNr() === $vorherigeNr);
    }
}
