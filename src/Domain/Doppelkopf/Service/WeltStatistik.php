<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

/**
 * Diagnose-Zähler für die Determinisierung der {@see ProfiHeuristik}.
 *
 * Hintergrund: `determinisiere()` verteilt die ungesehenen Karten sequenziell auf die drei
 * fremden Sitze und kann sich dabei festfahren – die letzte Karte passt zu keinem Sitz mehr,
 * weil alle passenden Hände schon voll sind oder in der Farbe frei sind. Dann wird verworfen
 * und neu gemischt; reicht auch das nicht, fällt der ganze Rollout aus.
 *
 * Betroffen sind bevorzugt stark eingeschränkte Stellungen (viele bekannte Farb-Freiheiten,
 * also späte Stiche) – ausgerechnet die informativsten. Diese Klasse misst, wie oft das
 * wirklich passiert, bevor über einen Umbau entschieden wird (Sievers' CAP-Algorithmus zieht
 * per Konstruktion immer eine konsistente Verteilung).
 *
 * Reines Zählwerk ohne Einfluss aufs Spiel. Ausgelesen wird es vom `app:bot:turnier`.
 */
final class WeltStatistik
{
    /** Aufrufe von `determinisiere()`. */
    public int $ziehungen = 0;

    /** Davon ohne Ergebnis (alle 8 internen Mischversuche liefen sich fest). */
    public int $ziehungenOhneErgebnis = 0;

    /** Anläufe, für einen Rollout eine brauchbare Welt zu finden. */
    public int $weltAnlaeufe = 0;

    /** Davon ergebnislos – der Rollout fällt komplett aus. */
    public int $weltAusfaelle = 0;

    /** Verworfen, weil die Welt eine stille Hochzeit ergab (1 gegen 3). */
    public int $verworfenEinGegenDrei = 0;

    public function zuruecksetzen(): void
    {
        $this->ziehungen             = 0;
        $this->ziehungenOhneErgebnis = 0;
        $this->weltAnlaeufe          = 0;
        $this->weltAusfaelle         = 0;
        $this->verworfenEinGegenDrei = 0;
    }

    /** Anteil der Determinisierungen ohne Ergebnis, in Prozent. */
    public function quoteZiehungenOhneErgebnis(): float
    {
        return $this->ziehungen === 0 ? 0.0 : 100.0 * $this->ziehungenOhneErgebnis / $this->ziehungen;
    }

    /** Anteil der Rollouts, die mangels Welt ausfallen, in Prozent. */
    public function quoteWeltAusfaelle(): float
    {
        return $this->weltAnlaeufe === 0 ? 0.0 : 100.0 * $this->weltAusfaelle / $this->weltAnlaeufe;
    }

    /** Anteil der gezogenen Welten, die als 1-gegen-3 verworfen wurden, in Prozent. */
    public function quoteEinGegenDrei(): float
    {
        $gezogen = $this->ziehungen - $this->ziehungenOhneErgebnis;

        return $gezogen === 0 ? 0.0 : 100.0 * $this->verworfenEinGegenDrei / $gezogen;
    }
}
