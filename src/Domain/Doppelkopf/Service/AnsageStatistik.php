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
 * Gezählt wird in **Partei-Blättern**, nicht in Spieler-Abfragen: eine Partei sagt an, sobald
 * *einer* ihrer beiden Spieler die Schwelle reißt, und sie sagt genau einmal an. Die Erhebung
 * fasst die Abfragen eines Blattes deshalb je Partei zum Höchstwert zusammen. Ohne das zählt
 * jedes Blatt so oft, wie seine Spieler gefragt wurden – und weil eine Partei nach ihrer Ansage
 * nicht mehr gefragt wird, sind das bei den ansagenden (starken) Blättern systematisch weniger
 * als bei den schweigenden (schwachen). Die erste Fassung dieser Klasse hatte genau den Fehler
 * und wies die Re-Haltequote dadurch mit 45 % statt 71 % aus.
 *
 * Reines Zählwerk, per Default abgeschaltet. Beeinflusst die Ansage an keiner Stelle.
 */
final class AnsageStatistik
{
    /**
     * Abfragen des laufenden Blattes, die noch auf ihren Ausgang warten.
     *
     * @var list<array{team: string, wert: float, fristPasst: bool}>
     */
    private array $offen = [];

    /**
     * Abgeschlossene Partei-Blätter samt Ausgang: je Blatt und Partei ein Eintrag mit dem
     * höchsten Blattwert, den einer ihrer Spieler an einem zulässigen Zeitpunkt hatte.
     *
     * @var list<array{team: string, wert: float, gewonnen: bool}>
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
     * Verbucht eine Abfrage der Erstansage.
     *
     * Erfasst wird **nach** dem Damen-Gate und unabhängig davon, ob die Augen-Schwelle gerade
     * reicht – sonst wären genau die Fälle unsichtbar, die eine gelockerte Schwelle zusätzlich
     * ansagen würde. Der Aufrufer ruft nur an Zeitpunkten auf, an denen die Erstansage nach der
     * TSR-Frist überhaupt noch zulässig ist; `$fristPasst` sagt darüber hinaus, ob die Heuristik
     * an diesem Zeitpunkt ansagen *würde* (bei `letztmöglich` also erst am letzten zulässigen).
     */
    public function verbucheErstansage(Team $team, float $wert, bool $fristPasst): void
    {
        if (!$this->aktiv) {
            return;
        }

        $this->offen[] = ['team' => $team->value, 'wert' => $wert, 'fristPasst' => $fristPasst];
    }

    /**
     * Schließt das Blatt ab: fasst die Abfragen je Partei zu einem Eintrag zusammen und trägt
     * den Ausgang nach.
     *
     * Gehalten hat eine Erstansage, wenn die ansagende Partei ihre Augen erreicht. Nach
     * TSR 7.1.1.3 braucht Re dafür 121; Kontra genügen 120, sobald **auch Re angesagt** hat,
     * sonst sind es ebenfalls 121. `$reHatAngesagt` ist dabei das Re-Verhalten des tatsächlich
     * gespielten Blattes – unter einer anderen Schwelle sagte auch Re anders an, was diese
     * Rechnung nicht abbilden kann (siehe Klassenkommentar).
     *
     * @param array<string, int> $augenProTeam Team-Wert → erspielte Augen
     */
    public function blattAbschliessen(array $augenProTeam, bool $reHatAngesagt = false): void
    {
        if (!$this->aktiv) {
            return;
        }

        foreach ([Team::RE, Team::KONTRA] as $team) {
            $werte = [];
            foreach ($this->offen as $eintrag) {
                if ($eintrag['team'] === $team->value && $eintrag['fristPasst']) {
                    $werte[] = $eintrag['wert'];
                }
            }

            if ($werte === []) {
                continue;
            }

            $noetig = $team === Team::RE || !$reHatAngesagt ? 121 : 120;

            $this->punkte[] = [
                'team'     => $team->value,
                'wert'     => max($werte),
                'gewonnen' => ($augenProTeam[$team->value] ?? 0) >= $noetig,
            ];
        }

        $this->offen = [];
    }

    /**
     * Was eine Schwelle bewirkt hätte – je Partei getrennt, weil sie getrennt gesetzt wird.
     *
     * Gezählt wird in Partei-Blättern: die Partei hätte angesagt, wenn ihr Höchstwert die
     * Schwelle erreicht.
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
                if ($p['wert'] < $s) {
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

    /** Partei-Blätter mit möglicher Erstansage – die Grundgesamtheit des Sweeps. */
    public function entscheidungspunkte(): int
    {
        return count($this->punkte);
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
