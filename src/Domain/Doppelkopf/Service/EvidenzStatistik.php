<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

/**
 * Diagnose für {@see TeamEvidenz}: wie oft feuert ein weiches Signal – und wie oft liegt es richtig?
 *
 * Der Unterschied zu Schritt 1 von Hebel 3 ist der Grund, warum es diese Klasse gibt. Dort waren
 * die Fakten **hart** (wer „Re" ansagt, hält eine Kreuz-Dame), und die einzige interessante Zahl
 * war die Häufigkeit. Weiche Evidenz kann dagegen **falsch** sein, und ein Signal mit 55 %
 * Trefferquote ist wertlos: es würde die Determinisierung genauso oft in die Irre führen wie
 * zurechtrücken. Gemessen werden deshalb drei Größen:
 *
 *  - **Trefferquote** – wie oft stimmt die Aussage mit der Wahrheit überein. Nur der Abstand
 *    zu 50 % ist verwertbare Information.
 *  - **Deckung** – wie oft liefert das Signal eine Aussage über ein Sitzpaar, das für den
 *    Beobachter nicht ohnehin schon feststeht. Das ist die Effekt-Obergrenze.
 *  - **Zeitpunkt** – aus welchem Stich die Aussage stammt. Evidenz aus Stich 2 kann die
 *    Determinisierung zehn Stiche lang lenken, Evidenz aus Stich 11 so gut wie nicht mehr.
 *    Ein Signal, das erst spät feuert, ist praktisch wertlos, egal wie zuverlässig es ist.
 *
 * Gezählt wird je **Beobachter und Stich** (vier Sichten je abgeschlossenem Stich), weil „neu"
 * von dem abhängt, was der jeweilige Sitz zu diesem Zeitpunkt öffentlich schon weiß. Die
 * Trefferquote bleibt davon unberührt – sie ist beobachterunabhängig und nur gleichmäßig
 * vierfach gewichtet.
 *
 * Die Wahrheit steht nur im Benchmark zur Verfügung ({@see \App\Domain\Doppelkopf\Simulation\Partie::teamProSitzplatz()}).
 * Diese Klasse wird deshalb ausschließlich aus der Simulation gefüttert und beeinflusst die
 * Kartenwahl an keiner Stelle – reines Zählwerk, wie {@see WeltStatistik}.
 */
final class EvidenzStatistik
{
    /**
     * Paar-Aussagen je Signaltyp.
     *
     * @var array<string, array{gesamt: int, richtig: int, neu: int, neuRichtig: int}>
     */
    private array $signale = [];

    /**
     * Aussagen je Stich-Nummer (0-basiert), nur die für den Beobachter neuen.
     *
     * @var array<int, int>
     */
    private array $proStich = [];

    /**
     * Richtung je Signal: sagt es „gleiches Team" (true) oder „verschiedene Teams" (false)?
     *
     * @var array<string, bool>
     */
    private array $richtung = [];

    /** Ausgewertete Beobachter-Sichten (4 je abgeschlossenem Stich). */
    public int $sichten = 0;

    /** Davon Sichten, in denen der Stich mindestens eine neue Aussage lieferte. */
    public int $sichtenMitNeuerAussage = 0;

    /** Fremde Sitze insgesamt betrachtet (3 je Sicht). */
    public int $fremdeSitze = 0;

    /** Davon hart bekannt – dort greift Schritt 1 bereits, weiche Evidenz trägt nichts bei. */
    public int $fremdeSitzeHartBekannt = 0;

    /**
     * Verbucht eine einzelne Paar-Aussage.
     *
     * @param bool $richtig      stimmt die Aussage mit den wahren Teams überein?
     * @param bool $neu          war das Paar für den Beobachter vorher unbestimmt?
     * @param bool $gleichesTeam Richtung der Aussage – bestimmt die Basisrate
     */
    public function verbuche(string $signal, bool $richtig, bool $neu, int $stichNr, bool $gleichesTeam): void
    {
        $this->richtung[$signal] = $gleichesTeam;
        $this->signale[$signal] ??= ['gesamt' => 0, 'richtig' => 0, 'neu' => 0, 'neuRichtig' => 0];
        $this->signale[$signal]['gesamt']++;
        if ($richtig) {
            $this->signale[$signal]['richtig']++;
        }

        if (!$neu) {
            return;
        }

        $this->signale[$signal]['neu']++;
        if ($richtig) {
            $this->signale[$signal]['neuRichtig']++;
        }

        $this->proStich[$stichNr] = ($this->proStich[$stichNr] ?? 0) + 1;
    }

    /**
     * Auswertung je Signal, absteigend nach Häufigkeit.
     *
     * Entscheidend ist die Spalte **Vorsprung**, nicht die rohe Trefferquote. Bei 2 gegen 2 ist
     * ein beliebiger anderer Sitz schon von sich aus in zwei Dritteln der Fälle ein Gegner –
     * ein „Gegner"-Signal mit 67 % Treffern weiß also gar nichts. Nur der Abstand zu dieser
     * Basisrate ist Information, und `z` sagt, ob er mehr als Rauschen ist.
     *
     * @return list<array{signal: string, gesamt: int, quote: float, neu: int, quoteNeu: float, proSicht: float, basis: float, vorsprung: float, z: float}>
     */
    public function auswertung(): array
    {
        $zeilen = [];
        foreach ($this->signale as $signal => $z) {
            // „gleiches Team" trifft auf einen von drei fremden Sitzen zu, „verschiedene" auf zwei.
            $basis    = ($this->richtung[$signal] ?? true) ? 1 / 3 : 2 / 3;
            $quoteNeu = $z['neu'] === 0 ? 0.0 : $z['neuRichtig'] / $z['neu'];
            $se       = $z['neu'] === 0 ? 0.0 : sqrt($basis * (1 - $basis) / $z['neu']);

            $zeilen[] = [
                'signal'    => $signal,
                'gesamt'    => $z['gesamt'],
                'quote'     => $z['gesamt'] === 0 ? 0.0 : 100.0 * $z['richtig'] / $z['gesamt'],
                'neu'       => $z['neu'],
                'quoteNeu'  => 100.0 * $quoteNeu,
                'proSicht'  => $this->sichten === 0 ? 0.0 : $z['neu'] / $this->sichten,
                'basis'     => 100.0 * $basis,
                'vorsprung' => 100.0 * ($quoteNeu - $basis),
                'z'         => $se === 0.0 ? 0.0 : ($quoteNeu - $basis) / $se,
            ];
        }

        usort($zeilen, static fn(array $a, array $b) => $b['gesamt'] <=> $a['gesamt']);

        return $zeilen;
    }

    /**
     * Neue Aussagen je Stich-Nummer (1-basiert ausgegeben), aufsteigend.
     *
     * @return list<array{stich: int, anzahl: int, anteil: float}>
     */
    public function proStich(): array
    {
        $summe = array_sum($this->proStich);
        if ($summe === 0) {
            return [];
        }

        $stiche = $this->proStich;
        ksort($stiche);

        $zeilen = [];
        foreach ($stiche as $nr => $anzahl) {
            $zeilen[] = ['stich' => $nr + 1, 'anzahl' => $anzahl, 'anteil' => 100.0 * $anzahl / $summe];
        }

        return $zeilen;
    }

    /** Anteil der fremden Sitze, deren Team schon hart bekannt ist, in Prozent. */
    public function quoteHartBekannt(): float
    {
        return $this->fremdeSitze === 0 ? 0.0 : 100.0 * $this->fremdeSitzeHartBekannt / $this->fremdeSitze;
    }

    /** Anteil der Sichten mit mindestens einer neuen Aussage, in Prozent. */
    public function quoteSichtenMitNeuerAussage(): float
    {
        return $this->sichten === 0 ? 0.0 : 100.0 * $this->sichtenMitNeuerAussage / $this->sichten;
    }

    public function istLeer(): bool
    {
        return $this->sichten === 0;
    }

    public function zuruecksetzen(): void
    {
        $this->signale                = [];
        $this->proStich               = [];
        $this->richtung               = [];
        $this->sichten                = 0;
        $this->sichtenMitNeuerAussage = 0;
        $this->fremdeSitze            = 0;
        $this->fremdeSitzeHartBekannt = 0;
    }
}
