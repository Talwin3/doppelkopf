<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Enum\Team;

/**
 * Diagnose für die Erstansage-Schwellen ({@see \App\Domain\Doppelkopf\ValueObject\AnsageSchwellen}).
 *
 * Hintergrund: die heutigen Werte 75/85 sind **gegen ANFAENGER** kalibriert (je ~4580 Blätter) –
 * also gegen einen Gegner, der nie ansagt und ein Zuviel an Ansagen nie bestraft. Genau das
 * führt der Bot-Stärke-Plan als Messfalle 1. Ob die Schwellen gegen einen bestrafenden Gegner
 * richtig liegen, ist nie geprüft worden.
 *
 * Statt für jede Kandidatenschwelle einen eigenen Lauf zu fahren, wird an jedem
 * Entscheidungspunkt der **Blattwert** mitgeschrieben, an dem die Schwelle gemessen wird. Aus
 * diesen Rohwerten fällt die Ansagezahl für **jede** Schwelle ab, ohne das Spiel neu zu
 * spielen – dasselbe Verfahren, mit dem P5-Hebel 1c den ganzen Gewichts-Sweep aus einer
 * 15-Minuten-Diagnose gewonnen hat.
 *
 * ⚠️ Was die Rohwerte **nicht** liefern: eine strengere Schwelle ändert das Spiel, nicht nur die
 * Buchhaltung. Wer nicht ansagt, spielt anders weiter, und die Gegner reagieren anders. Die
 * abgeleiteten Haltequoten sind deshalb eine **Obergrenze für die Entscheidungsqualität**, kein
 * Ersatz für den Messlauf – sie beantworten die Frage „bewegt eine Verschiebung überhaupt genug
 * Ansagen, als dass sich ein Lauf lohnt?".
 *
 * Reines Zählwerk, per Default abgeschaltet. Beeinflusst die Ansage an keiner Stelle.
 */
final class AnsageStatistik
{
    /**
     * Entscheidungspunkte des laufenden Blattes, die noch auf ihren Ausgang warten.
     *
     * @var list<array{team: string, wert: float, fristPasst: bool}>
     */
    private array $offen = [];

    /**
     * Abgeschlossene Entscheidungspunkte samt Ausgang.
     *
     * @var list<array{team: string, wert: float, fristPasst: bool, gewonnen: bool}>
     */
    private array $punkte = [];

    private bool $aktiv = false;

    public function aktivieren(): void
    {
        $this->aktiv = true;
    }

    public function istAktiv(): bool
    {
        return $this->aktiv;
    }

    /**
     * Verbucht einen Entscheidungspunkt für die Erstansage.
     *
     * Erfasst wird **nach** dem Damen-Gate und unabhängig davon, ob die Augen-Schwelle gerade
     * reicht – sonst wären genau die Fälle unsichtbar, die eine gelockerte Schwelle zusätzlich
     * ansagen würde.
     */
    public function verbucheErstansage(Team $team, float $wert, bool $fristPasst): void
    {
        if (!$this->aktiv) {
            return;
        }

        $this->offen[] = ['team' => $team->value, 'wert' => $wert, 'fristPasst' => $fristPasst];
    }

    /**
     * Schließt das Blatt ab und trägt den Ausgang nach.
     *
     * @param array<string, int> $augenProTeam Team-Wert → erspielte Augen
     */
    public function blattAbschliessen(array $augenProTeam): void
    {
        if (!$this->aktiv) {
            return;
        }

        foreach ($this->offen as $eintrag) {
            $eigene = $augenProTeam[$eintrag['team']] ?? 0;

            $this->punkte[] = [...$eintrag, 'gewonnen' => $eigene >= 121];
        }

        $this->offen = [];
    }

    /**
     * Was eine Schwelle bewirkt hätte – je Partei getrennt, weil sie getrennt gesetzt wird.
     *
     * Gezählt werden nur Entscheidungspunkte, an denen die Frist ohnehin passt: nur dort hängt
     * die Ansage tatsächlich an der Augen-Schwelle.
     *
     * @return list<array{schwelle: float, re: int, reQuote: float, contra: int, contraQuote: float}>
     */
    public function sweep(float $von = 50.0, float $bis = 105.0, float $schritt = 5.0): array
    {
        $zeilen = [];
        for ($s = $von; $s <= $bis; $s += $schritt) {
            $zaehler = [Team::RE->value => 0, Team::KONTRA->value => 0];
            $treffer = [Team::RE->value => 0, Team::KONTRA->value => 0];

            foreach ($this->punkte as $p) {
                if (!$p['fristPasst'] || $p['wert'] < $s) {
                    continue;
                }
                $zaehler[$p['team']]++;
                if ($p['gewonnen']) {
                    $treffer[$p['team']]++;
                }
            }

            $re = $zaehler[Team::RE->value];
            $ko = $zaehler[Team::KONTRA->value];

            $zeilen[] = [
                'schwelle'    => $s,
                're'          => $re,
                'reQuote'     => $re === 0 ? 0.0 : 100.0 * $treffer[Team::RE->value] / $re,
                'contra'      => $ko,
                'contraQuote' => $ko === 0 ? 0.0 : 100.0 * $treffer[Team::KONTRA->value] / $ko,
            ];
        }

        return $zeilen;
    }

    /** Entscheidungspunkte mit passender Frist – die Grundgesamtheit des Sweeps. */
    public function entscheidungspunkte(): int
    {
        return count(array_filter($this->punkte, static fn(array $p) => $p['fristPasst']));
    }

    public function istLeer(): bool
    {
        return $this->punkte === [];
    }

    public function zuruecksetzen(): void
    {
        $this->offen  = [];
        $this->punkte = [];
    }
}
