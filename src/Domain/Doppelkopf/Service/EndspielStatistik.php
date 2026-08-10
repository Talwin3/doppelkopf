<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

/**
 * Reichweite der exakten Endspielsuche – wie oft sie greift und wie oft sie etwas ändert.
 *
 * Die zweite Zahl ist die wichtigere: eine Suche, die zwar läuft, aber dieselbe Karte wählt
 * wie der Greedy-Rollout, kann die Spielstärke nicht bewegen. Sie begrenzt damit den
 * überhaupt erreichbaren Effekt nach oben – und entscheidet, ob ein stundenlanger
 * Stärkelauf sich lohnt.
 *
 * Gefüllt nur im Diagnosemodus (`--endspiel-diagnose`), der dafür beide Bewertungen rechnet
 * und deshalb ungefähr doppelt so teuer ist. Im Normalbetrieb bleiben die Zähler auf 0.
 */
final class EndspielStatistik
{
    /** Züge, in denen die Engine überhaupt bewertet hat (mehr als eine Karte zur Wahl). */
    public int $zuege = 0;

    /** Davon Züge, in denen mindestens eine Welt exakt ausgerechnet wurde. */
    public int $zuegeMitEndspiel = 0;

    /** Davon Züge, in denen die exakte Suche eine andere Karte wählt als der Greedy-Rollout. */
    public int $zuegeWahlGeaendert = 0;

    /**
     * Davon Züge, in denen die eingestellte {@see \App\Enum\EndspielPolitik} eine andere Karte
     * wählt als Minimax – bleibt 0, solange Minimax selbst läuft.
     *
     * Beantwortet die Frage, ob ein anderes Gegnermodell überhaupt etwas bewegen *kann*:
     * wählt es dieselben Karten wie Minimax, erübrigt sich jeder Stärkelauf darüber.
     */
    public int $zuegeAbweichendVonMinimax = 0;

    public function zuruecksetzen(): void
    {
        $this->zuege                     = 0;
        $this->zuegeMitEndspiel          = 0;
        $this->zuegeWahlGeaendert        = 0;
        $this->zuegeAbweichendVonMinimax = 0;
    }

    /** Anteil der Züge, in denen die exakte Suche greift, in Prozent. */
    public function quoteMitEndspiel(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->zuegeMitEndspiel / $this->zuege;
    }

    /** Anteil der Züge, in denen sie die Wahl ändert – bezogen auf alle Züge, in Prozent. */
    public function quoteWahlGeaendert(): float
    {
        return $this->zuege === 0 ? 0.0 : 100.0 * $this->zuegeWahlGeaendert / $this->zuege;
    }

    /** Anteil der Wahländerungen unter den Zügen, in denen die Suche überhaupt greift. */
    public function trefferquoteImEndspiel(): float
    {
        return $this->zuegeMitEndspiel === 0
            ? 0.0
            : 100.0 * $this->zuegeWahlGeaendert / $this->zuegeMitEndspiel;
    }

    /** Anteil der Züge mit Suche, in denen die Politik von Minimax abweicht. */
    public function quoteAbweichendVonMinimax(): float
    {
        return $this->zuegeMitEndspiel === 0
            ? 0.0
            : 100.0 * $this->zuegeAbweichendVonMinimax / $this->zuegeMitEndspiel;
    }
}
