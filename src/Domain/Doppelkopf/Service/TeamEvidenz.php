<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;

/**
 * Weiche Hinweise auf die Parteizugehörigkeit, abgelesen am bisherigen Spielverlauf.
 *
 * Hebel 3, zweiter Schritt. Der erste Schritt nutzte nur **harte** Fakten (wer „Re" ansagt,
 * hält eine Kreuz-Dame) und brachte +0,35 Punkte. Was danach bleibt, ist das, was ein
 * menschlicher Spieler aus dem Verhalten der anderen schließt.
 *
 * Der Ansatzpunkt steckt in {@see RolloutPolitik}: dort hängen genau drei Entscheidungen am
 * Team des Stichführenden – und jede davon ist von außen sichtbar.
 *
 *  - **Schmieren**: wer hohe Augen auf einen Stich legt, den ein anderer führt, und ihn dabei
 *    nicht schlägt, hält den Führenden für seinen Partner.
 *  - **Stechen**: wer den Führenden überbietet, hält ihn für einen Gegner.
 *
 * Beides sind **Vermutungen**, keine Tatsachen: ein Spieler kann zum Schmieren gezwungen sein
 * (nur noch hohe Karten in der geforderten Farbe) oder aus taktischen Gründen den eigenen
 * Partner überstechen. Deshalb wird jede Aussage einem Signaltyp zugeordnet, dessen
 * Trefferquote sich getrennt messen lässt ({@see EvidenzStatistik}) – erst danach wird
 * entschieden, welches Signal die Determinisierung überhaupt beeinflussen darf.
 *
 * Reine Logik ohne DB und ohne Zufall. Schaut ausschließlich auf öffentlich sichtbare Karten,
 * nie in fremde Hände.
 */
final class TeamEvidenz
{
    /** Ab dieser Augenzahl gilt eine Karte als „geschmiert" (Zehn = 10, Ass = 11). */
    public const SCHMIER_SCHWELLE = 10;

    /** Hohe Augen abgeworfen (nicht bedient), als letzter Spieler – der Stich ist sicher. */
    public const SIGNAL_SCHMIEREN_ABWURF_LETZTER = 'schmieren-abwurf-letzter';

    /** Hohe Augen abgeworfen, aber es kommt noch jemand – riskanter, also überlegter. */
    public const SIGNAL_SCHMIEREN_ABWURF_FRUEH = 'schmieren-abwurf-frueh';

    /** Hohe Augen bedient, als letzter Spieler. */
    public const SIGNAL_SCHMIEREN_BEDIENT_LETZTER = 'schmieren-bedient-letzter';

    /** Hohe Augen bedient, mit noch offenen Mitspielern – am ehesten schlichter Zwang. */
    public const SIGNAL_SCHMIEREN_BEDIENT_FRUEH = 'schmieren-bedient-frueh';

    /** Überbietet den bisherigen Stichführenden. */
    public const SIGNAL_STECHEN = 'stechen';

    public function __construct(private readonly RolloutPolitik $politik) {}

    /**
     * Liest alle Paar-Aussagen aus dem Spielverlauf.
     *
     * Bewusst als Aussage über ein **Sitzpaar**, nicht über einen einzelnen Sitz: das Signal
     * entsteht immer zwischen dem Handelnden und dem Stichführenden. Die Auflösung auf „mein
     * Partner / mein Gegner" ist ein zweiter Schritt und darf die Messung der Rohsignale
     * nicht verfälschen.
     *
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $stiche abgeschlossene Stiche in Spielreihenfolge
     *
     * @return list<array{a: int, b: int, gleichesTeam: bool, signal: string, stichNr: int}>
     */
    public function auswerten(array $stiche, TrumpfOrdnung $ordnung): array
    {
        $aussagen = [];
        foreach ($stiche as $stichNr => $stich) {
            foreach ($this->fuerStich($stich, $stichNr, $ordnung) as $aussage) {
                $aussagen[] = $aussage;
            }
        }

        return $aussagen;
    }

    /**
     * Die Aussagen eines einzelnen abgeschlossenen Stichs.
     *
     * Getrennt zugänglich, weil eine Auswertung erst am Spielende in die Irre führen würde:
     * bis dahin sind fast alle Kreuz-Damen gefallen und damit fast alle Parteien ohnehin
     * öffentlich. Ob ein Signal etwas **Neues** sagt, entscheidet sich zu dem Zeitpunkt, an
     * dem es fällt – deshalb wird Stich für Stich ausgewertet.
     *
     * @param list<array{sitzplatz: int, karte: Karte}> $stich
     *
     * @return list<array{a: int, b: int, gleichesTeam: bool, signal: string, stichNr: int}>
     */
    public function fuerStich(array $stich, int $stichNr, TrumpfOrdnung $ordnung): array
    {
        if (count($stich) < 2) {
            return [];
        }

        $aussagen   = [];
        $angespielt = $stich[0]['karte'];
        $gefordert  = $ordnung->istTrumpf($angespielt) ? null : $ordnung->fehlfarbe($angespielt);

        $fuehrerSitz  = $stich[0]['sitzplatz'];
        $fuehrerKarte = $angespielt;
        $letzterIndex = count($stich) - 1;

        // Ab der zweiten Karte: jede Karte ist eine Reaktion auf den aktuellen Führenden.
        foreach (array_slice($stich, 1, null, true) as $index => $eintrag) {
            $sitz  = $eintrag['sitzplatz'];
            $karte = $eintrag['karte'];

            if ($this->politik->schlaegt($karte, $fuehrerKarte, $angespielt, $ordnung)) {
                // Wer den Führenden überbietet, nimmt ihm den Stich weg.
                $aussagen[]   = [
                    'a'            => $sitz,
                    'b'            => $fuehrerSitz,
                    'gleichesTeam' => false,
                    'signal'       => self::SIGNAL_STECHEN,
                    'stichNr'      => $stichNr,
                ];
                $fuehrerSitz  = $sitz;
                $fuehrerKarte = $karte;
            } elseif ($karte->augen() >= self::SCHMIER_SCHWELLE) {
                // Hohe Augen an einen Stich, den ein anderer hält – das tut nur, wer den
                // Führenden für seinen Partner hält. Ob die Karte erzwungen war, ist von
                // außen nicht sicher zu erkennen; der Abwurf ist das stärkere Signal, weil
                // dort die ganze Hand zur Wahl stand.
                $bedient = $this->hatBedient($karte, $gefordert, $ordnung);
                $letzter = $index === $letzterIndex;

                $aussagen[] = [
                    'a'            => $sitz,
                    'b'            => $fuehrerSitz,
                    'gleichesTeam' => true,
                    'signal'       => match (true) {
                        $bedient && $letzter  => self::SIGNAL_SCHMIEREN_BEDIENT_LETZTER,
                        $bedient              => self::SIGNAL_SCHMIEREN_BEDIENT_FRUEH,
                        $letzter              => self::SIGNAL_SCHMIEREN_ABWURF_LETZTER,
                        default               => self::SIGNAL_SCHMIEREN_ABWURF_FRUEH,
                    },
                    'stichNr'      => $stichNr,
                ];
            }
        }

        return $aussagen;
    }

    /** Hat die Karte die geforderte Kategorie bedient? ($gefordert === null heißt Trumpf.) */
    private function hatBedient(Karte $karte, ?Kartenfarbe $gefordert, TrumpfOrdnung $ordnung): bool
    {
        if ($gefordert === null) {
            return $ordnung->istTrumpf($karte);
        }

        return !$ordnung->istTrumpf($karte) && $ordnung->fehlfarbe($karte) === $gefordert;
    }
}
