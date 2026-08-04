<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\AnsageTyp;
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
    /**
     * Unerreichbare Schwelle – ein Blatt hat höchstens 12 Karten.
     *
     * **Die Absagen sind damit bewusst abgeschaltet.** Die Mechanik steht (Heuristik,
     * Voraussetzungskette, Produktivpfad), aber die Trumpfzahl taugt nicht als Auslöser.
     * Gemessen gegen dieselbe Engine ohne Absagen, je 6140 Blätter:
     *
     *     keine 90 ab  9 Trümpfen   −0,1056   −0,1249 … −0,0863
     *     keine 90 ab 10 Trümpfen   −0,0112   −0,0179 … −0,0046
     *     keine 90 ab 11 Trümpfen   −0,0020   −0,0042 … +0,0003
     *     keine 90 ab 12 Trümpfen   ±0,0000   (feuert nie)
     *
     * Je häufiger die Absage fällt, desto teurer – ein Optimum gibt es nur bei „nie".
     * Das deckt sich mit dem Essener System: „keine 90" verlangt 88,89 % Sicherheit, und
     * neun Trümpfe sagen darüber schlicht nichts. Sinnvoll wird die Absage erst mit einer
     * echten Schätzung von P(Gegner unter 90) aus Rollouts.
     */
    public const NIE = 13;

    /** Die Absagen in ihrer Voraussetzungsreihenfolge – jede setzt die vorige voraus. */
    public const ABSAGE_KETTE = [
        AnsageTyp::KEINE_NEUN,
        AnsageTyp::KEINE_SECHS,
        AnsageTyp::KEINE_DREI,
        AnsageTyp::SCHWARZ,
    ];

    /**
     * @param array<string, int> $minTruempfeAbsage AnsageTyp-Wert → Mindest-Trümpfe
     */
    public function __construct(
        public int $minTruempfeRe = 7,
        public int $minTruempfeContra = 8,
        public array $minTruempfeAbsage = [],
    ) {}

    /** Wie vor der Essener Auswertung: beide Parteien gleich behandelt, keine Absagen. */
    public static function gleich(int $minTruempfe = 7): self
    {
        return new self($minTruempfe, $minTruempfe);
    }

    public function minTruempfe(Team $team): int
    {
        return $team === Team::RE ? $this->minTruempfeRe : $this->minTruempfeContra;
    }

    /** Mindest-Trümpfe für eine Absage; {@see self::NIE}, wenn nicht konfiguriert. */
    public function minTruempfeFuerAbsage(AnsageTyp $typ): int
    {
        return $this->minTruempfeAbsage[$typ->value] ?? self::NIE;
    }

    public function machtAbsagen(): bool
    {
        foreach ($this->minTruempfeAbsage as $min) {
            if ($min < self::NIE) {
                return true;
            }
        }

        return false;
    }

    public function beschreibung(): string
    {
        $text = sprintf('Ansage ab %d/%d Trümpfen (Re/Contra)', $this->minTruempfeRe, $this->minTruempfeContra);
        if (!$this->machtAbsagen()) {
            return $text . ', ohne Absagen';
        }

        $teile = [];
        foreach (self::ABSAGE_KETTE as $typ) {
            $min = $this->minTruempfeFuerAbsage($typ);
            $teile[] = sprintf('%s ab %s', $typ->label(), $min >= self::NIE ? 'nie' : (string) $min);
        }

        return $text . ', ' . implode(', ', $teile);
    }
}
