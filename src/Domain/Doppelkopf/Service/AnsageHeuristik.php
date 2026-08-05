<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\Team;

/**
 * Entscheidet, ob ein Bot früh im Spiel "Re" bzw. "Contra" ansagt (verdoppelt den
 * Spielwert). Reine Logik ohne DB.
 *
 * Angesagt wird nur bei deutlich starker Hand (viele Trümpfe + hohe Trümpfe), damit
 * Bots die Ansage nicht verschenken – für Contra strenger als für Re, weil KONTRA
 * strukturell schwächer steht ({@see AnsageSchwellen}). Das eigene Team ist dem Bot fair
 * bekannt (eigene Karten) – die Ansage deckt es ohnehin auf. Anfänger sagen nie an.
 */
final class AnsageHeuristik
{
    public function __construct(
        private readonly BlattBewertung $blattBewertung = new BlattBewertung(),
    ) {}

    /**
     * @param Karte[]      $hand         Aktuelle Handkarten
     * @param ?AnsageSchwellen $schwellen Mindeststärke je Partei (null = Standard)
     * @param AnsageTyp[]  $teamAnsagen  Was das eigene Team bereits angesagt hat
     * @param ?callable(AnsageTyp): int $fristFuer Mindestzahl Handkarten für eine Ansage.
     *        Nur damit kann die Heuristik den letztmöglichen Zeitpunkt erkennen; fehlt sie,
     *        wird wie früher zum frühestmöglichen Zeitpunkt angesagt. Der Aufrufer liefert
     *        sie aus seiner eigenen Quelle, damit die Fristen nicht ein drittes Mal im Code
     *        stehen (Produktivpfad rechnet dabei den Hochzeits-Nachlass mit ein).
     * @param ?Karte[] $starthand Die ausgeteilte Hand. Ob sie oder das Restblatt bewertet
     *        wird, entscheidet {@see AnsageSchwellen::$bewerteRestblatt} – die Wahl hängt
     *        daran, ob die erspielten Augen mitgezählt werden, und gehört deshalb zur
     *        Schwellen-Konfiguration, nicht zum Aufrufer.
     * @param int $erspielteAugen Augen, die die eigene Partei in den abgeschlossenen Stichen
     *        schon geholt hat – der erste Summand der Essener Formel.
     * @return ?AnsageTyp Nächste sinnvolle Ansage, oder null
     */
    public function willAnsagen(
        BotStaerke $staerke,
        array $hand,
        ?Team $team,
        TrumpfOrdnung $ordnung,
        ?AnsageSchwellen $schwellen = null,
        array $teamAnsagen = [],
        ?callable $fristFuer = null,
        ?array $starthand = null,
        int $erspielteAugen = 0,
    ): ?AnsageTyp {
        if ($staerke === BotStaerke::ANFAENGER || $team === null) {
            return null;
        }

        $schwellen ??= new AnsageSchwellen();
        $bewertungsHand = $schwellen->bewerteRestblatt ? $hand : ($starthand ?? $hand);

        $truempfe = 0;
        $damen    = 0;
        $kreuzDamen = 0;
        foreach ($bewertungsHand as $k) {
            if ($ordnung->istTrumpf($k)) {
                $truempfe++;
            }
            if ($k->wert === Kartenwert::DAME) {
                $damen++;
                if ($k->farbe === Kartenfarbe::KREUZ) {
                    $kreuzDamen++;
                }
            }
        }

        // Hohe Trümpfe sind Voraussetzung für jede Ansage.
        if ($kreuzDamen < 1 && $damen < 2) {
            return null;
        }

        $grundansage = $team === Team::RE ? AnsageTyp::RE : AnsageTyp::CONTRA;

        if (!in_array($grundansage, $teamAnsagen, true)) {
            return $this->blattReicht($bewertungsHand, $truempfe, $team, $ordnung, $schwellen, $erspielteAugen)
                && $this->zeitpunktPasst($grundansage, $hand, $schwellen, $fristFuer)
                    ? $grundansage
                    : null;
        }

        // Grundansage steht – die Absagen der Reihe nach anbieten. Jede setzt die vorige
        // voraus, deshalb zählt nur die erste noch offene Stufe.
        foreach (AnsageSchwellen::ABSAGE_KETTE as $absage) {
            if (in_array($absage, $teamAnsagen, true)) {
                continue;
            }

            return $truempfe >= $schwellen->minTruempfeFuerAbsage($absage)
                && $this->zeitpunktPasst($absage, $hand, $schwellen, $fristFuer)
                    ? $absage
                    : null;
        }

        return null;
    }

    /**
     * Ist das Blatt stark genug für die Erstansage?
     *
     * Wahlweise über die Trumpfzahl (grob, historisch) oder über eine Blattbewertung in
     * Augen nach dem Essener System ({@see BlattBewertung}) – dort sind auch die Schwellen
     * her (Re ab 75, Kontra ab 85 Augen).
     *
     * Das System rechnet dabei „die bis zur Ansage erzielte Augenzahl **plus** die Summe der
     * Erwartungswerte": Wer den ersten Stich schon geholt hat, braucht vom Blatt weniger zu
     * erwarten. Deshalb geht das Augenkonto der eigenen Partei mit in die Summe.
     *
     * @param Karte[] $bewertungsHand
     */
    private function blattReicht(
        array $bewertungsHand,
        int $truempfe,
        Team $team,
        TrumpfOrdnung $ordnung,
        AnsageSchwellen $schwellen,
        int $erspielteAugen,
    ): bool {
        $minAugen = $schwellen->minAugen($team);
        if ($minAugen === null) {
            return $truempfe >= $schwellen->minTruempfe($team);
        }

        $bereits = $schwellen->mitErspieltenAugen ? (float) $erspielteAugen : 0.0;

        return $this->blattBewertung->augen($bewertungsHand, $ordnung) + $bereits >= $minAugen;
    }

    /**
     * Ist jetzt der richtige Moment für diese Ansage?
     *
     * Nach dem Essener System wartet man in der Regel bis zum **letztmöglichen** Zeitpunkt:
     * jede früher gemachte Ansage verschenkt die Information, die der laufende Stich noch
     * bringt – und verrät der Gegenpartei früher, woran sie ist. Der Bot sagt deshalb erst
     * an, wenn seine Hand auf die Frist abgeschmolzen ist.
     *
     * @param Karte[] $hand
     * @param ?callable(AnsageTyp): int $fristFuer
     */
    private function zeitpunktPasst(
        AnsageTyp $typ,
        array $hand,
        AnsageSchwellen $schwellen,
        ?callable $fristFuer,
    ): bool {
        if (!$schwellen->letztmoeglich || $fristFuer === null) {
            return true; // frühestmöglich – das alte Verhalten
        }

        return count($hand) <= $fristFuer($typ);
    }
}
