<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\Team;

/**
 * Mindest-Blattstärke, ab der ein Bot „Re" bzw. „Contra" ansagt
 * ({@see \App\Domain\Doppelkopf\Service\AnsageHeuristik}).
 *
 * Getrennt nach Partei, weil KONTRA strukturell schwächer steht: RE hält beide
 * Kreuz-Damen und holt im Schnitt 68 der 120 Augen je Spieler, KONTRA nur 52; RE gewinnt
 * rund zwei Drittel aller Normalspiele (im eigenen Simulator mit 65,5 % bestätigt). Das
 * Essener System zieht daraus eine höhere Mindeststärke für die Contra-Erstansage
 * (≥ 85 statt ≥ 75 Augen) — siehe `ref-essener-system`.
 *
 * Die Trumpfzahl ist dafür nur ein grober Ersatz; eine echte Blattbewertung in Augen
 * (Essener Verliererrechnung) wäre der nächste Schritt.
 *
 * Gemessen gegen die alte Fassung 7/7 (gepaart, je 6140 Blätter, Seed 4242):
 *
 *     7/8   +0,0333   +0,0204 … +0,0463   ← gewählt
 *     7/9   +0,0284   +0,0122 … +0,0446
 *     8/8   +0,0248   +0,0066 … +0,0431
 *     6/8   +0,0222   +0,0041 … +0,0403
 *     6/7   −0,0111   −0,0242 … +0,0021
 *
 * 7/8 ist auf einem zweiten Seed bestätigt (+0,0143) und mindestens so gut wie 8/8
 * (+0,0136 im direkten Vergleich). Dass 6/7 als einzige Variante verliert, zeigt: es ist
 * wirklich die Asymmetrie, nicht bloß mehr Strenge — Re *lockerer* schadet.
 */
final readonly class AnsageSchwellen
{
    public function __construct(
        public int $minTruempfeRe = 7,
        public int $minTruempfeContra = 8,
    ) {}

    /** Wie vor der Essener Auswertung: beide Parteien gleich behandelt. */
    public static function gleich(int $minTruempfe = 7): self
    {
        return new self($minTruempfe, $minTruempfe);
    }

    public function minTruempfe(Team $team): int
    {
        return $team === Team::RE ? $this->minTruempfeRe : $this->minTruempfeContra;
    }

    public function beschreibung(): string
    {
        return sprintf('Ansage ab %d/%d Trümpfen (Re/Contra)', $this->minTruempfeRe, $this->minTruempfeContra);
    }
}
