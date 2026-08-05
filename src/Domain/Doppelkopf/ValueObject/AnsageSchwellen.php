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
     * Mindest-Blattstärke in Augen für die Erstansage ({@see \App\Domain\Doppelkopf\Service\BlattBewertung}),
     * gerechnet als erspielte Augen **plus** Blattbewertung ({@see self::$mitErspieltenAugen}).
     *
     * Das sind **genau die Werte des Essener Systems** (Re ab 75, Kontra ab 85). Solange nur
     * die Karten bewertet wurden, wirkten sie zu streng und das Optimum lag bei 65/75; mit
     * dem ersten Summanden der Originalformel stimmen sie. Gemessen gegen ANFAENGER, je
     * ~4580 Blätter, Mittel aus zwei Seeds (777/4242):
     *
     *     Restblatt 55/65   +0,1357
     *     Restblatt 60/70   +0,1454
     *     Restblatt 65/75   +0,1597
     *     Restblatt 70/80   +0,1652
     *     Restblatt 75/85   +0,1680   ← gewählt
     *     Restblatt 80/90   +0,1639
     *     Restblatt 85/95   +0,1605
     *     Restblatt 90/100  +0,1484
     *
     * Das Maximum ist damit nach beiden Seiten eingegrenzt und auf beiden Seeds
     * gleichgerichtet. Gegenüber dem vorherigen Stand (Starthand, ohne erspielte Augen,
     * 65/75: +0,1620) sind das **+0,006** – weniger als das Konfidenzintervall eines
     * einzelnen Laufs (±0,025), also kein einzeln nachgewiesener Gewinn, sondern die
     * Entscheidung für die theoretisch richtige Rechnung unter mehreren gleich guten.
     */
    public const AUGEN_RE = 75.0;
    public const AUGEN_CONTRA = 85.0;

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
     *
     * `$mitErspieltenAugen`: die von der eigenen Partei bereits geholten Stichaugen zur
     * Blattbewertung addieren?
     *
     * Das vervollständigt die Essener Formel („bis zur Ansage erzielte Augenzahl plus Summe
     * der Erwartungswerte") – bis dahin bewertete der Bot nur seine Karten. Sinnvoll ist es
     * nur zusammen mit `$letztmoeglich`: wer sofort ansagt, hat noch nichts erspielt.
     *
     * `$bewerteRestblatt`: die verbleibenden Handkarten bewerten – oder die ausgeteilte Hand?
     *
     * Nur zusammen mit `$mitErspieltenAugen` sinnvoll, und dann zwingend: die gespielte Karte
     * ist in den erspielten Augen schon abgerechnet, sie noch einmal als Erwartungswert zu
     * zählen wäre doppelt. Umgekehrt gilt dasselbe – ohne die erspielten Augen fällt der
     * Beitrag der gespielten Karte ganz weg, und die ausgeteilte Hand ist die bessere Basis.
     * Gemessen (gegen ANFAENGER, je ~4580 Blätter, Mittel aus zwei Seeds, jeweils an der
     * eigenen optimalen Schwelle):
     *
     *     Starthand, ohne erspielte   +0,1620   (Stand vor Teil B, 65/75)
     *     Restblatt, ohne erspielte   +0,1574   (65/75)
     *     Starthand, mit erspielten   +0,1642   (75/85 – zählt die gespielte Karte doppelt)
     *     Restblatt, mit erspielten   +0,1680   (75/85 – gewählt)
     *
     * Die zweite Zeile ist die Kontrollgruppe: Das Restblatt allein ist **schlechter**. Der
     * Vorsprung kommt also von den erspielten Augen, nicht vom Wechsel der bewerteten Hand.
     */
    public function __construct(
        public int $minTruempfeRe = 7,
        public int $minTruempfeContra = 8,
        public array $minTruempfeAbsage = [],
        public bool $letztmoeglich = true,
        public ?float $minAugenRe = self::AUGEN_RE,
        public ?float $minAugenContra = self::AUGEN_CONTRA,
        public bool $mitErspieltenAugen = true,
        public bool $bewerteRestblatt = true,
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
        return $this->abgewandelt(minAugenRe: $re, minAugenContra: $contra);
    }

    public function mitErspieltenAugen(bool $an): self
    {
        return $this->abgewandelt(mitErspieltenAugen: $an);
    }

    public function mitBewerteRestblatt(bool $an): self
    {
        return $this->abgewandelt(bewerteRestblatt: $an);
    }

    /** Kopie mit einzelnen geänderten Werten – PHP 8.4 kennt noch kein `clone with`. */
    private function abgewandelt(
        ?float $minAugenRe = null,
        ?float $minAugenContra = null,
        ?bool $mitErspieltenAugen = null,
        ?bool $bewerteRestblatt = null,
    ): self {
        return new self(
            minTruempfeRe: $this->minTruempfeRe,
            minTruempfeContra: $this->minTruempfeContra,
            minTruempfeAbsage: $this->minTruempfeAbsage,
            letztmoeglich: $this->letztmoeglich,
            minAugenRe: $minAugenRe ?? $this->minAugenRe,
            minAugenContra: $minAugenContra ?? $this->minAugenContra,
            mitErspieltenAugen: $mitErspieltenAugen ?? $this->mitErspieltenAugen,
            bewerteRestblatt: $bewerteRestblatt ?? $this->bewerteRestblatt,
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
            'Ansage ab %s%s (Re/Contra), %s, %s',
            $this->minAugenRe !== null
                ? sprintf('%.0f/%.0f Augen', $this->minAugenRe, $this->minAugenContra ?? $this->minAugenRe)
                : sprintf('%d/%d Trümpfen', $this->minTruempfeRe, $this->minTruempfeContra),
            $this->minAugenRe !== null && $this->mitErspieltenAugen ? ' inkl. erspielter' : '',
            $this->bewerteRestblatt ? 'Restblatt' : 'Starthand',
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
