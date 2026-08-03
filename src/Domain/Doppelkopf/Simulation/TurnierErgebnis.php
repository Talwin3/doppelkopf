<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

/**
 * Auswertung eines Self-Play-Turniers: gepaarte Differenz der Turnierpunkte je Spieler
 * und Partie zwischen Strategie A und B, mit 95-%-Konfidenzintervall.
 *
 * Positiv = A ist besser. Da jedes Blatt gespiegelt gespielt wird (A und B tauschen die
 * Sitze), ist die Differenz frei vom Kartenglück – das Konfidenzintervall fällt dadurch
 * deutlich enger aus als bei unabhängigen Partien.
 */
final class TurnierErgebnis
{
    /** @param list<float> $differenzen Ø Turnierpunkte je A-Spieler und Partie, pro Blatt */
    public function __construct(
        public readonly string $nameA,
        public readonly string $nameB,
        public readonly array $differenzen,
        public readonly int $uebersprungeneBlaetter,
        public readonly int $ansagenA,
        public readonly int $ansagenB,
        public readonly float $dauerSek,
    ) {}

    public function anzahlBlaetter(): int
    {
        return count($this->differenzen);
    }

    /** Ø Turnierpunkte je Spieler und Partie, die A gegenüber B mehr holt. */
    public function mittelwert(): float
    {
        $n = $this->anzahlBlaetter();

        return $n === 0 ? 0.0 : array_sum($this->differenzen) / $n;
    }

    /** Stichproben-Standardabweichung der gepaarten Differenzen. */
    public function standardabweichung(): float
    {
        $n = $this->anzahlBlaetter();
        if ($n < 2) {
            return 0.0;
        }

        $mittel = $this->mittelwert();
        $summe  = 0.0;
        foreach ($this->differenzen as $d) {
            $summe += ($d - $mittel) ** 2;
        }

        return sqrt($summe / ($n - 1));
    }

    /** Standardfehler des Mittelwerts. */
    public function standardfehler(): float
    {
        $n = $this->anzahlBlaetter();

        return $n === 0 ? 0.0 : $this->standardabweichung() / sqrt($n);
    }

    /** Halbe Breite des 95-%-Konfidenzintervalls. */
    public function konfidenzHalbbreite(): float
    {
        return 1.96 * $this->standardfehler();
    }

    /** Ist der Unterschied auf dem 95-%-Niveau von null verschieden? */
    public function signifikant(): bool
    {
        $halbbreite = $this->konfidenzHalbbreite();

        return $halbbreite > 0.0 && abs($this->mittelwert()) > $halbbreite;
    }

    /**
     * Wie viele Blätter wären nötig, um einen Unterschied dieser Größe nachzuweisen?
     * (Grobe Planungshilfe: n ≈ (1,96·s / Effekt)².)
     */
    public function benoetigteBlaetter(float $effekt): ?int
    {
        $s = $this->standardabweichung();
        if ($s <= 0.0 || $effekt <= 0.0) {
            return null;
        }

        return (int) ceil((1.96 * $s / $effekt) ** 2);
    }
}
