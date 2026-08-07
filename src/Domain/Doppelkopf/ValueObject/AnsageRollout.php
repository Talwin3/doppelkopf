<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\ValueObject;

use App\Enum\AnsageTyp;

/**
 * Konfiguration der Ansage-Entscheidung per Monte-Carlo-Rollout (P5 Hebel 5).
 *
 * Steckt in {@see AnsageSchwellen::$rollout}; ist das Feld null, entscheidet der Bot wie
 * bisher über die {@see \App\Domain\Doppelkopf\Service\BlattBewertung}. Nur die Stufe
 * PROFI kann Rollouts liefern – die anderen Stufen haben keine PIMC-Engine und bleiben
 * deshalb unverändert bei der Blattbewertung.
 *
 * **Warum überhaupt Rollouts?** Die Blattbewertung schätzt die Gewinnchance über ein
 * statisches Punkteschema aus einer fremden Quelle. Die Absagen scheitern genau daran: für
 * „keine 90" braucht es laut Essener System 88,89 % Sicherheit, und die Trumpfzahl (oder
 * eine Blattsumme) sagt darüber schlicht nichts – gemessen war jede Absage-Schwelle teurer
 * als „nie" ({@see AnsageSchwellen::NIE}). P(Gegner unter 90) ist dagegen aus denselben
 * Welten ablesbar, die die Kartenwahl ohnehin zieht.
 *
 * **Warum ist das billig?** Mit {@see AnsageSchwellen::$letztmoeglich} hat jeder Ansagetyp
 * genau einen Entscheidungszeitpunkt (Re/Contra bei 11 Handkarten, keine 90 bei 10, keine
 * 60 bei 9, keine 30 bei 8, schwarz bei 7). Das sind höchstens 5 Rollout-Durchgänge je Bot
 * und Blatt gegenüber rund 12 × 10 für die Kartenwahl.
 */
final readonly class AnsageRollout
{
    /**
     * Mindest-Sicherheiten des Essener Systems (`ref-essener-system`), falls nach
     * Wahrscheinlichkeit statt nach Erwartungswert entschieden wird.
     *
     * Sie folgen aus dem Verhältnis von Gewinn zu Verlust der jeweiligen Ansage: „keine 90"
     * bringt einen Punkt, kostet bei Verfehlen aber das ganze Spiel – daraus die 8/9.
     */
    public const SICHERHEIT_ESSEN = [
        'RE'          => 0.5,
        'CONTRA'      => 0.5,
        'KEINE_NEUN'  => 0.8889,
        'KEINE_SECHS' => 0.9167,
        'KEINE_DREI'  => 0.9333,
        'SCHWARZ'     => 0.9474,
    ];

    /**
     * @param bool                 $evVergleich true = ansagen, wenn der Ø Spielwert steigt
     *        (an unserer eigenen Wertung kalibriert); false = nach den Wahrscheinlichkeits-
     *        schwellen des Essener Systems entscheiden.
     * @param bool                 $nurAbsagen Die Erstansage weiter über die
     *        {@see \App\Domain\Doppelkopf\Service\BlattBewertung} entscheiden und die Rollouts
     *        nur für die Absagen einsetzen.
     *
     *        **Unvermessene Hypothese**, kein Messergebnis. Sie stützt sich auf eine
     *        Beobachtung, die für sich genommen nicht trägt: Gegen einen gleich starken
     *        Gegner sagt die Rollout-Erstansage rund 35 % öfter an und hält dabei schlechter
     *        (63–66 % statt 68 %). Naheliegend wäre, dass sie dort der Blattbewertung
     *        unterlegen ist und die Absagen den einzigen Beitrag liefern – belegt ist das
     *        nicht, die beiden Seeds widersprachen sich (+0,171 und −0,028). Der Schalter
     *        existiert, damit die Kombination messbar ist, nicht weil sie sich bewährt hätte.
     * @param bool                 $absagenNachSchwellen Nur für die Absagen doch nach
     *        Wahrscheinlichkeit entscheiden, während Re/Contra beim Erwartungswert bleibt.
     *        Eigener Schalter, weil sich sonst zwei Regeln auf einmal ändern und ein
     *        gemessener Unterschied nicht mehr zuzuordnen ist. Nützlich, weil die EV-Regel
     *        bei den Absagen sehr zurückhaltend ist (rund 2–3 % der Partien).
     * @param bool                 $absagen     Auch keine 90/60/30/schwarz per Rollout entscheiden?
     *        Bewusst getrennt schaltbar, damit sich die Erstansage und die Absagen einzeln
     *        vermessen lassen – zwei Schalter in einem Lauf zu drehen war schon einmal der
     *        Grund, warum ein Befund nicht zuzuordnen war.
     * @param array<string, float> $sicherheit  AnsageTyp-Wert → Mindest-Sicherheit (0…1)
     * @param int                  $welten      Rollouts je Entscheidung
     */
    public function __construct(
        public bool $evVergleich = true,
        public bool $absagen = false,
        public array $sicherheit = self::SICHERHEIT_ESSEN,
        public int $welten = 60,
        public bool $absagenNachSchwellen = false,
        public bool $nurAbsagen = false,
    ) {}

    /** Gilt für diesen Typ die Erwartungswert-Regel? */
    private function nachErwartungswert(AnsageTyp $typ): bool
    {
        $istAbsage = $typ !== AnsageTyp::RE && $typ !== AnsageTyp::CONTRA;

        return $istAbsage && $this->absagenNachSchwellen ? false : $this->evVergleich;
    }

    public function mindestSicherheit(AnsageTyp $typ): float
    {
        return $this->sicherheit[$typ->value] ?? 1.0;
    }

    /** Trägt die Schätzung diese Ansage? */
    public function traegt(AnsageAussichten $aussichten, AnsageTyp $typ): bool
    {
        if ($aussichten->leer()) {
            return false;
        }

        return $this->nachErwartungswert($typ)
            ? $aussichten->lohnt($typ)
            : $aussichten->sicherheit($typ) >= $this->mindestSicherheit($typ);
    }

    public function beschreibung(): string
    {
        $absagen = match (true) {
            !$this->absagen              => 'ohne Absagen',
            $this->absagenNachSchwellen  => 'Absagen nach Essener Schwellen',
            default                      => 'mit Absagen',
        };

        if ($this->nurAbsagen) {
            return sprintf('Erstansage über Blattbewertung, Absagen per Rollout (%s, %d Welten)',
                $this->absagenNachSchwellen ? 'Essener Schwellen' : 'Erwartungswert',
                $this->welten,
            );
        }

        return sprintf(
            'Ansage per Rollout (%s, %d Welten, %s)',
            $this->evVergleich ? 'Erwartungswert' : 'Essener Schwellen',
            $this->welten,
            $absagen,
        );
    }
}
