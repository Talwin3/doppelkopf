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

    /**
     * Mindest-Blattstärke in Augen für die Erstansage ({@see \App\Domain\Doppelkopf\Service\BlattBewertung}).
     *
     * Das Essener System nennt Re ab 75, Kontra ab 85. Gemessen ist bei uns **lockerer
     * besser** – gegen ANFAENGER, je 4540 Blätter, Mittel aus zwei Seeds:
     *
     *     Trumpfzahl 7/8 (alt)   +0,1614
     *     Augen 62/72            +0,1803
     *     Augen 65/75            +0,1842   ← gewählt
     *     Augen 68/78            +0,1799
     *     Augen 75/85            +0,1550   (die Werte des Systems)
     *     Augen 85/95            +0,1245
     *
     * Der Abstand zu 75/85 erklärt sich aus der Bewertung selbst: sie liegt gegenüber der
     * Vorlage rund 15 % zu hoch (Beispielblatt 2 des Originals: 52,5 dort, ~62 hier), weil
     * die Quelle an der Erwartungswert-Tabelle beschädigt ist. Die Schwelle fängt das ab –
     * gemessen statt geglaubt.
     */
    public const AUGEN_RE = 65.0;
    public const AUGEN_CONTRA = 75.0;

    /** Die Absagen in ihrer Voraussetzungsreihenfolge – jede setzt die vorige voraus. */
    public const ABSAGE_KETTE = [
        AnsageTyp::KEINE_NEUN,
        AnsageTyp::KEINE_SECHS,
        AnsageTyp::KEINE_DREI,
        AnsageTyp::SCHWARZ,
    ];

    /**
     * @param array<string, int> $minTruempfeAbsage AnsageTyp-Wert → Mindest-Trümpfe
     *
     * `$letztmoeglich`: bis zum letztmöglichen Zeitpunkt mit der Ansage warten, statt sie
     * sofort zu machen?
     *
     * Das Essener System empfiehlt das Warten, weil jede frühe Ansage die Information des
     * laufenden Stichs verschenkt. **Gemessen bringt es bei uns nichts** (gegen ANFAENGER,
     * je 4540 Blätter, gleiche Karten): frühestmöglich +0,1458 · letztmöglich +0,1488 –
     * die Intervalle überlappen fast vollständig.
     *
     * Der Grund ist die heutige Frist: Re/Contra verlangen ≥ 11 Handkarten, zwischen
     * frühestem und letztem Zeitpunkt liegt also **genau ein Stich**. Das Essener System
     * spricht dagegen von Ansagen „im ersten bis dritten Stich" – es setzt ein viel
     * größeres Fenster voraus, als die heutige TSR lässt. So wenig Zusatzinformation
     * verändert die Entscheidung kaum.
     *
     * Trotzdem als Default an: Es kostet nichts, verrät der Gegenpartei später, woran sie
     * ist, und ist die **Voraussetzung** dafür, die bis dahin erspielten Augen in die
     * Blattbewertung aufzunehmen (Essener: „bis zur Ansage erzielte Augenzahl plus Summe
     * der Erwartungswerte") – ohne Warten gibt es gar nichts nachzubewerten.
     */
    public function __construct(
        public int $minTruempfeRe = 7,
        public int $minTruempfeContra = 8,
        public array $minTruempfeAbsage = [],
        public bool $letztmoeglich = true,
        public ?float $minAugenRe = self::AUGEN_RE,
        public ?float $minAugenContra = self::AUGEN_CONTRA,
    ) {}

    /**
     * Mindest-Blattstärke in Augen ({@see \App\Domain\Doppelkopf\Service\BlattBewertung}),
     * oder null, solange nach der gröberen Trumpfzahl entschieden wird. Das Essener System
     * nennt Re ab 75, Kontra ab 85.
     */
    public function minAugen(Team $team): ?float
    {
        return $team === Team::RE ? $this->minAugenRe : $this->minAugenContra;
    }

    public function mitAugenSchwellen(float $re, float $contra): self
    {
        return new self(
            $this->minTruempfeRe,
            $this->minTruempfeContra,
            $this->minTruempfeAbsage,
            $this->letztmoeglich,
            $re,
            $contra,
        );
    }

    /** Wie vor der Essener Auswertung: beide Parteien gleich behandelt, keine Absagen. */
    public static function gleich(int $minTruempfe = 7): self
    {
        return self::nachTrumpfzahl($minTruempfe, $minTruempfe);
    }

    /** Ältere, gröbere Entscheidung allein über die Trumpfzahl – für Vergleichsmessungen. */
    public static function nachTrumpfzahl(int $re = 7, int $contra = 8): self
    {
        return new self($re, $contra, [], true, null, null);
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
        $text = sprintf(
            'Ansage ab %s (Re/Contra), %s',
            $this->minAugenRe !== null
                ? sprintf('%.0f/%.0f Augen', $this->minAugenRe, $this->minAugenContra ?? $this->minAugenRe)
                : sprintf('%d/%d Trümpfen', $this->minTruempfeRe, $this->minTruempfeContra),
            $this->letztmoeglich ? 'letztmöglich' : 'frühestmöglich',
        );
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
