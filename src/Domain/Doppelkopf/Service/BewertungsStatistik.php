<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

/**
 * Diagnose zur Bewertungsformel der {@see ProfiHeuristik}: `v = w · s + e`
 * (s = signierte Turnierpunkte, e = Augen, w = 0 ≡ reine Augen-Bewertung).
 *
 * Beantwortet zwei Fragen, für die es sonst tagelange Stärkeläufe bräuchte:
 *
 * 1. **Ändert das Gewicht überhaupt die Karte?** Aus einer einzigen Diagnose fällt der
 *    komplette Gewichts-Sweep ab: pro Zug stehen die Summen S_i und E_i je Kandidat fest,
 *    die Wahl für ein beliebiges w ist daraus als `argmax_i (w·S_i + E_i)` ausrechenbar.
 *    Wählt ein Gewicht dieselben Karten wie die Augen, kann es die Spielstärke nicht bewegen.
 *
 * 2. ⭐ **Ist die Rangfolge bei r=60 überhaupt abgesichert?** Das ist die eigentliche
 *    Varianz-Hypothese: `v = 500·s + e` wird von einer fast binären Größe dominiert
 *    (Sieg/Niederlage), deren Mittelwert über nur 60 Welten stark streut. Gemessen wird
 *    deshalb je Zug der Abstand zwischen bestem und zweitbestem Kandidaten **in Einheiten
 *    seines eigenen Standardfehlers** (t = d̄ / SE, gepaart über die Welten, weil alle
 *    Kandidaten dieselben Welten sehen). Ein t unter 1,96 heißt: die beiden Karten sind bei
 *    dieser Weltenzahl nicht unterscheidbar, die Wahl zwischen ihnen ist ein Münzwurf.
 *    Fällt t für die Punkte deutlich niedriger aus als für die Augen, ist die Hypothese
 *    bestätigt — die Punkte-Bewertung tauscht Bias gegen Varianz und verliert den Tausch.
 *
 * Reines Zählwerk ohne Einfluss aufs Spiel; gefüllt nur mit `--bewertungs-diagnose`.
 * Ausgelesen wird es vom `app:bot:turnier`.
 */
final class BewertungsStatistik
{
    /** Gewichte, für die der Sweep mitgerechnet wird. 0 ist die Augen-Bewertung (Referenz). */
    public const GEWICHTE = [0, 50, 100, 200, 500];

    /** Schwelle, ab der ein Abstand als abgesichert gilt (95 % zweiseitig). */
    private const T_SCHWELLE = 1.96;

    /**
     * Wie viel mehr Welten die Hochrechnung unterstellt (60 → 300, der offene Vergleich aus P5.3).
     *
     * Der Standardfehler fällt mit √n, also wächst t um √5. Damit lässt sich aus **einem**
     * Lauf bei r=60 beantworten, ob eine Verfünffachung der Weltenzahl die Rangfolgen
     * überhaupt absichern würde – ohne den fünffach teuren Lauf zu fahren.
     */
    private const HOCHRECHNUNG_FAKTOR = 5;

    /**
     * Ab wie vielen Augen Abstand eine Kartenwahl sachlich zählt. 5 Augen sind eine Zehn
     * gegen einen Buben – darunter ist die Wahl auch bei perfekter Kenntnis fast beliebig.
     */
    private const GLEICHWERTIG_AUGEN = 5.0;

    /** Züge mit echter Wahl (mindestens zwei Kandidaten und mindestens zwei Welten). */
    public int $zuege = 0;

    /** Summe der Kandidaten über alle Züge – für den Schnitt. */
    public int $kandidatenSumme = 0;

    /** Summe der ausgewerteten Welten über alle Züge – für den Schnitt. */
    public int $weltenSumme = 0;

    /**
     * Gewicht → Züge, in denen dieses Gewicht eine andere Karte wählt als die Augen (w = 0).
     *
     * @var array<int, int>
     */
    public array $wahlGeaendert = [];

    /** Züge, in denen die Augen-Rangfolge zwischen Platz 1 und 2 nicht abgesichert ist. */
    public int $unentschiedenAugen = 0;

    /** Züge, in denen die Punkte-Rangfolge (w = 500) zwischen Platz 1 und 2 nicht abgesichert ist. */
    public int $unentschiedenPunkte = 0;

    /**
     * Züge, die auch bei fünffacher Weltenzahl nicht abgesichert wären (Hochrechnung t·√5),
     * Augen-Maßstab – beantwortet den offenen Vergleich r=300 gegen r=60 rechnerisch.
     */
    public int $unentschiedenBei300Augen = 0;

    /** Dasselbe im Punkte-Maßstab. */
    public int $unentschiedenBei300Punkte = 0;

    /**
     * Züge, in denen alle Welten dasselbe sagen (Streuung null, t = ∞) – die Rangfolge steht
     * ohne jeden Zweifel. Sie bleiben aus dem t-Schnitt heraus, sonst wäre er unendlich.
     */
    public int $einstimmigAugen = 0;

    /** Dasselbe im Punkte-Maßstab. */
    public int $einstimmigPunkte = 0;

    /** Summe der endlichen t-Werte (Abstand Platz 1 zu Platz 2 in Standardfehlern), Augen. */
    public float $tSummeAugen = 0.0;

    /** Summe der endlichen t-Werte im Punkte-Maßstab (w = 500). */
    public float $tSummePunkte = 0.0;

    /**
     * Alle t-Werte, für den Median. Der Mittelwert taugt hier nicht: die Verteilung hat einen
     * schweren Schwanz (klare Stellungen liefern t in dreistelliger Höhe), er schwankt deshalb
     * über Seeds hinweg um ein Vielfaches, während der Median stabil bleibt.
     *
     * @var list<float>
     */
    public array $tWerteAugen = [];

    /** @var list<float> */
    public array $tWertePunkte = [];

    /**
     * ⭐ Absoluter Abstand zwischen Platz 1 und 2 in **Augen**, je Zug.
     *
     * Die Gegenprobe zur Trennschärfe: ein Münzwurf zwischen zwei gleich guten Karten kostet
     * nichts. Erst wenn der Abstand sachlich ins Gewicht fällt *und* im Rauschen untergeht,
     * verliert die Engine wirklich etwas.
     *
     * @var list<float>
     */
    public array $abstaendeAugen = [];

    /** Züge, in denen die beiden besten Karten weniger als {@see self::GLEICHWERTIG_AUGEN} Augen trennen. */
    public int $praktischGleichwertig = 0;

    /** Züge mit spürbarem Abstand, der aber im Rauschen untergeht – der teure Fall. */
    public int $teuerUndUnentschieden = 0;

    public function __construct()
    {
        $this->zuruecksetzen();
    }

    public function zuruecksetzen(): void
    {
        $this->zuege                     = 0;
        $this->kandidatenSumme           = 0;
        $this->weltenSumme               = 0;
        $this->unentschiedenAugen        = 0;
        $this->unentschiedenPunkte       = 0;
        $this->unentschiedenBei300Augen  = 0;
        $this->unentschiedenBei300Punkte = 0;
        $this->einstimmigAugen           = 0;
        $this->einstimmigPunkte          = 0;
        $this->tSummeAugen               = 0.0;
        $this->tSummePunkte              = 0.0;
        $this->tWerteAugen               = [];
        $this->tWertePunkte              = [];
        $this->abstaendeAugen            = [];
        $this->praktischGleichwertig     = 0;
        $this->teuerUndUnentschieden     = 0;
        $this->wahlGeaendert             = [];
        foreach (self::GEWICHTE as $w) {
            if ($w !== 0) {
                $this->wahlGeaendert[$w] = 0;
            }
        }
    }

    /**
     * Nimmt einen bewerteten Zug auf.
     *
     * @param list<list<float>> $punkteProWelt Kandidat-Index → signierter Turnierwert je Welt
     * @param list<list<float>> $augenProWelt  Kandidat-Index → Augen je Welt (gleiche Reihenfolge)
     */
    public function erfasse(array $punkteProWelt, array $augenProWelt): void
    {
        $kandidaten = count($punkteProWelt);
        $welten     = $kandidaten === 0 ? 0 : count($punkteProWelt[0]);
        if ($kandidaten < 2 || $welten < 2) {
            return; // ohne Alternative oder ohne Streuung ist nichts zu diagnostizieren
        }

        $this->zuege++;
        $this->kandidatenSumme += $kandidaten;
        $this->weltenSumme     += $welten;

        $wahlJeGewicht = [];
        foreach (self::GEWICHTE as $w) {
            $wahlJeGewicht[$w] = $this->bester($punkteProWelt, $augenProWelt, $w);
        }
        foreach (self::GEWICHTE as $w) {
            if ($w !== 0 && $wahlJeGewicht[$w] !== $wahlJeGewicht[0]) {
                $this->wahlGeaendert[$w]++;
            }
        }

        ['t' => $tAugen, 'abstand' => $abstandAugen]
            = $this->trennschaerfe($punkteProWelt, $augenProWelt, 0, $wahlJeGewicht[0]);
        $this->tWerteAugen[]    = $tAugen;
        $this->abstaendeAugen[] = $abstandAugen;
        if (is_infinite($tAugen)) {
            $this->einstimmigAugen++;
        } else {
            $this->tSummeAugen += $tAugen;
        }
        if ($tAugen < self::T_SCHWELLE) {
            $this->unentschiedenAugen++;
        }
        if ($tAugen * sqrt(self::HOCHRECHNUNG_FAKTOR) < self::T_SCHWELLE) {
            $this->unentschiedenBei300Augen++;
        }
        if ($abstandAugen < self::GLEICHWERTIG_AUGEN) {
            $this->praktischGleichwertig++;
        } elseif ($tAugen < self::T_SCHWELLE) {
            $this->teuerUndUnentschieden++;
        }

        ['t' => $tPunkte] = $this->trennschaerfe($punkteProWelt, $augenProWelt, 500, $wahlJeGewicht[500]);
        $this->tWertePunkte[] = $tPunkte;
        if (is_infinite($tPunkte)) {
            $this->einstimmigPunkte++;
        } else {
            $this->tSummePunkte += $tPunkte;
        }
        if ($tPunkte < self::T_SCHWELLE) {
            $this->unentschiedenPunkte++;
        }
        if ($tPunkte * sqrt(self::HOCHRECHNUNG_FAKTOR) < self::T_SCHWELLE) {
            $this->unentschiedenBei300Punkte++;
        }
    }

    /** Ø Kandidaten je bewertetem Zug. */
    public function kandidatenSchnitt(): float
    {
        return $this->zuege === 0 ? 0.0 : $this->kandidatenSumme / $this->zuege;
    }

    /** Ø ausgewertete Welten je bewertetem Zug. */
    public function weltenSchnitt(): float
    {
        return $this->zuege === 0 ? 0.0 : $this->weltenSumme / $this->zuege;
    }

    /** Anteil der Züge, in denen dieses Gewicht eine andere Karte wählt als die Augen, in Prozent. */
    public function quoteWahlGeaendert(int $gewicht): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * ($this->wahlGeaendert[$gewicht] ?? 0) / $this->zuege;
    }

    /** Anteil der Züge ohne abgesicherte Rangfolge, Augen-Maßstab, in Prozent. */
    public function quoteUnentschiedenAugen(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->unentschiedenAugen / $this->zuege;
    }

    /** Anteil der Züge ohne abgesicherte Rangfolge, Punkte-Maßstab, in Prozent. */
    public function quoteUnentschiedenPunkte(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->unentschiedenPunkte / $this->zuege;
    }

    /** Anteil der Züge, die auch bei fünffacher Weltenzahl unentschieden blieben (Augen). */
    public function quoteUnentschiedenBei300Augen(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->unentschiedenBei300Augen / $this->zuege;
    }

    /** Dasselbe im Punkte-Maßstab. */
    public function quoteUnentschiedenBei300Punkte(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->unentschiedenBei300Punkte / $this->zuege;
    }

    /** Anteil der Züge, in denen die beiden besten Karten praktisch gleichwertig sind, in Prozent. */
    public function quotePraktischGleichwertig(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->praktischGleichwertig / $this->zuege;
    }

    /**
     * ⭐ Anteil der Züge, in denen wirklich etwas auf dem Spiel steht *und* die Wahl trotzdem
     * ein Münzwurf ist – der einzige Anteil, an dem mehr Suche überhaupt etwas gewinnen könnte.
     */
    public function quoteTeuerUndUnentschieden(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->teuerUndUnentschieden / $this->zuege;
    }

    /** Median des t-Werts im Augen-Maßstab – robust gegen den schweren Schwanz der Verteilung. */
    public function tMedianAugen(): float
    {
        return $this->median($this->tWerteAugen);
    }

    /** Median des t-Werts im Punkte-Maßstab. */
    public function tMedianPunkte(): float
    {
        return $this->median($this->tWertePunkte);
    }

    /** Median des Abstands zwischen Platz 1 und 2 in Augen. */
    public function abstandMedianAugen(): float
    {
        return $this->median($this->abstaendeAugen);
    }

    /** @param list<float> $werte */
    private function median(array $werte): float
    {
        if ($werte === []) {
            return 0.0;
        }

        sort($werte);
        $n    = count($werte);
        $mitte = intdiv($n, 2);

        return $n % 2 === 1 ? $werte[$mitte] : ($werte[$mitte - 1] + $werte[$mitte]) / 2.0;
    }

    /** Ø t über die Züge mit echter Streuung – einstimmige Züge (t = ∞) bleiben draußen. */
    public function tSchnittAugen(): float
    {
        $n = $this->zuege - $this->einstimmigAugen;

        return $n <= 0 ? 0.0 : $this->tSummeAugen / $n;
    }

    /** Ø t im Punkte-Maßstab, ebenfalls ohne die einstimmigen Züge. */
    public function tSchnittPunkte(): float
    {
        $n = $this->zuege - $this->einstimmigPunkte;

        return $n <= 0 ? 0.0 : $this->tSummePunkte / $n;
    }

    /**
     * Index des besten Kandidaten für ein Gewicht. Bei Gleichstand der erste – dieselbe
     * Regel wie {@see ProfiHeuristik::bestenIndex}, damit der Vergleich fair bleibt.
     *
     * @param list<list<float>> $punkteProWelt
     * @param list<list<float>> $augenProWelt
     */
    private function bester(array $punkteProWelt, array $augenProWelt, int $gewicht): int
    {
        $bester = 0;
        $besterWert = null;
        foreach ($punkteProWelt as $i => $punkte) {
            $wert = $gewicht * array_sum($punkte) + array_sum($augenProWelt[$i]);
            if ($besterWert === null || $wert > $besterWert) {
                $bester     = $i;
                $besterWert = $wert;
            }
        }

        return $bester;
    }

    /**
     * Abstand zwischen bestem und zweitbestem Kandidaten in Standardfehlern.
     *
     * Gepaart über die Welten: alle Kandidaten sehen dieselbe Welt, die Differenz je Welt
     * kürzt das Kartenglück der Welt heraus. Genau das ist der Standardfehler, der zählt.
     *
     * @param list<list<float>> $punkteProWelt
     * @param list<list<float>> $augenProWelt
     *
     * @return array{t: float, abstand: float} t = |d̄| / SE(d̄) (0.0 bei entartetem Fall,
     *         INF bei Streuung null); abstand = |d̄| in den Einheiten des Maßstabs
     */
    private function trennschaerfe(array $punkteProWelt, array $augenProWelt, int $gewicht, int $bester): array
    {
        $wert = static function (array $punkte, array $augen) use ($gewicht): float {
            return $gewicht * array_sum($punkte) + array_sum($augen);
        };

        $zweiter     = null;
        $zweiterWert = null;
        foreach ($punkteProWelt as $i => $punkte) {
            if ($i === $bester) {
                continue;
            }
            $w = $wert($punkte, $augenProWelt[$i]);
            if ($zweiterWert === null || $w > $zweiterWert) {
                $zweiter     = $i;
                $zweiterWert = $w;
            }
        }
        if ($zweiter === null) {
            return ['t' => 0.0, 'abstand' => 0.0];
        }

        $welten = count($punkteProWelt[$bester]);
        $summe  = 0.0;
        $quadrate = 0.0;
        for ($r = 0; $r < $welten; $r++) {
            $d = ($gewicht * $punkteProWelt[$bester][$r] + $augenProWelt[$bester][$r])
                - ($gewicht * $punkteProWelt[$zweiter][$r] + $augenProWelt[$zweiter][$r]);
            $summe    += $d;
            $quadrate += $d * $d;
        }

        $mittel  = $summe / $welten;
        $varianz = ($quadrate - $welten * $mittel * $mittel) / ($welten - 1);
        if ($varianz <= 0.0) {
            // Alle Welten sagen dasselbe – die Rangfolge steht ohne jeden Zweifel.
            return ['t' => $mittel === 0.0 ? 0.0 : INF, 'abstand' => abs($mittel)];
        }

        return [
            't'       => abs($mittel) / sqrt($varianz / $welten),
            'abstand' => abs($mittel),
        ];
    }
}
