<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\Service\WertungsRechner;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Reine Logik-Tests für die DDV-nahe Abrechnung. Kein Kernel/DB nötig.
 * Sitzplätze: 1+3 = RE, 2+4 = KONTRA (Standardaufstellung).
 */
final class WertungsRechnerTest extends TestCase
{
    private WertungsRechner $rechner;
    private TrumpfOrdnung $ordnung;

    /** @var array<int, Team> */
    private array $teams;

    protected function setUp(): void
    {
        $this->rechner = new WertungsRechner(new StichGewinner());
        $this->ordnung = new NormalspielTrumpfOrdnung();
        $this->teams = [
            1 => Team::RE,
            2 => Team::KONTRA,
            3 => Team::RE,
            4 => Team::KONTRA,
        ];
    }

    public function testNormalspielReGewinntMitAllenSonderpunkten(): void
    {
        $stiche = [
            // Doppelkopf (43 Augen) + Fuchs gefangen: RE (Sitz 1, Dulle) erbeutet KONTRAs Karo-As
            $this->stich([[1, 'HERZ_ZEHN_1'], [2, 'KARO_ASS_1'], [3, 'KREUZ_ASS_1'], [4, 'PIK_ASS_1']]),
            // Zweiter Doppelkopf + zweiter Fuchs
            $this->stich([[1, 'HERZ_ZEHN_2'], [2, 'KARO_ASS_2'], [3, 'KREUZ_ASS_2'], [4, 'PIK_ASS_2']]),
            // Auffüllen, damit RE über 121 kommt
            $this->stich([[1, 'KREUZ_DAME_1'], [2, 'HERZ_ASS_1'], [3, 'HERZ_ASS_2'], [4, 'KREUZ_ZEHN_1']]),
            // Letzter Stich mit Kreuz-Bube → Karlchen
            $this->stich([[1, 'KREUZ_BUBE_1'], [2, 'PIK_NEUN_1'], [3, 'HERZ_NEUN_1'], [4, 'PIK_KOENIG_1']]),
        ];

        $ansagen = [
            ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
            ['sitzplatz' => 2, 'typ' => AnsageTyp::CONTRA],
            ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
        ];

        $w = $this->rechner->berechne($stiche, $this->teams, $this->ordnung, $ansagen, false, null);

        self::assertSame('RE', $w['sieger']);
        self::assertSame(127, $w['augen']['RE']);
        self::assertSame(0, $w['augen']['KONTRA']);

        // Alle erwarteten Positionen (alle an RE)
        self::assertPositionExists($w, 'Gewonnen', 'RE', 1);
        self::assertPositionExists($w, 'keine 90', 'RE', 1);
        self::assertPositionExists($w, 'keine 60', 'RE', 1);
        self::assertPositionExists($w, 'keine 30', 'RE', 1);
        self::assertPositionExists($w, 'Schwarz', 'RE', 1);
        self::assertPositionExists($w, 'Fuchs gefangen (2×)', 'RE', 2);
        self::assertPositionExists($w, 'Doppelkopf (2×)', 'RE', 2);
        self::assertPositionExists($w, 'Karlchen', 'RE', 1);
        self::assertPositionExists($w, 'Re angesagt', 'RE', 2);
        self::assertPositionExists($w, 'Contra angesagt', 'RE', 2);
        self::assertPositionExists($w, 'Absage: keine 90', 'RE', 1);

        // "Gegen die Alten" gibt es nur wenn KONTRA gewinnt
        self::assertNull($this->findePosition($w, 'Gegen die Alten'));

        self::assertSame(15, $w['summeProTeam']['RE']);
        self::assertSame(0, $w['summeProTeam']['KONTRA']);
        self::assertSame(15, $w['spielwert']);
    }

    public function testKontraGewinntBekommtGegenDieAlten(): void
    {
        // Ein Stich, von KONTRA (Sitz 2, führt mit Dulle) gewonnen → RE bleibt bei 0 Augen.
        $stiche = [
            $this->stich([[2, 'HERZ_ZEHN_1'], [1, 'KREUZ_ASS_1'], [3, 'PIK_ASS_1'], [4, 'HERZ_ASS_1']]),
        ];

        $w = $this->rechner->berechne($stiche, $this->teams, $this->ordnung, [], false, null);

        self::assertSame('KONTRA', $w['sieger']);
        self::assertPositionExists($w, 'Gewonnen', 'KONTRA', 1);
        self::assertPositionExists($w, 'Gegen die Alten', 'KONTRA', 1);
    }

    public function testSoloZaehltKeineFangSonderpunkte(): void
    {
        // Dieselbe Konstellation wie oben (Karo-As erbeutet, 43-Augen-Stich), aber als Solo:
        // Fuchs/Doppelkopf/Karlchen und "Gegen die Alten" entfallen.
        $stiche = [
            $this->stich([[1, 'HERZ_ZEHN_1'], [2, 'KARO_ASS_1'], [3, 'KREUZ_ASS_1'], [4, 'PIK_ASS_1']]),
            $this->stich([[1, 'KREUZ_BUBE_1'], [2, 'PIK_NEUN_1'], [3, 'HERZ_NEUN_1'], [4, 'PIK_KOENIG_1']]),
        ];

        // Solist sitzt auf 1 (RE), die anderen sind KONTRA.
        $teams = [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::KONTRA, 4 => Team::KONTRA];

        $w = $this->rechner->berechne($stiche, $teams, $this->ordnung, [], false, 1);

        self::assertNull($this->findePosition($w, 'Fuchs gefangen'));
        self::assertNull($this->findePosition($w, 'Fuchs gefangen (2×)'));
        self::assertNull($this->findePosition($w, 'Doppelkopf'));
        self::assertNull($this->findePosition($w, 'Doppelkopf (2×)'));
        self::assertNull($this->findePosition($w, 'Karlchen'));
        self::assertNull($this->findePosition($w, 'Gegen die Alten'));
    }

    public function testAnsagePunkteGehenAnGewinnerAuchWennAnsagerVerliert(): void
    {
        // KONTRA gewinnt; RE (Sitz 1) hatte Re angesagt → die +2 gehen trotzdem an KONTRA.
        $stiche = [
            $this->stich([[2, 'HERZ_ZEHN_1'], [1, 'KREUZ_ASS_1'], [3, 'PIK_ASS_1'], [4, 'HERZ_ASS_1']]),
        ];
        $ansagen = [['sitzplatz' => 1, 'typ' => AnsageTyp::RE]];

        $w = $this->rechner->berechne($stiche, $this->teams, $this->ordnung, $ansagen, false, null);

        self::assertSame('KONTRA', $w['sieger']);
        self::assertPositionExists($w, 'Re angesagt', 'KONTRA', 2);
    }

    // ── Ansagen als Verpflichtung (TSR 7.1.3) ──────────────────────────────────

    public function testGescheiterteAbsageVerliertTrotzMehrheitDerAugen(): void
    {
        // RE sagt "keine 90" ab, drückt KONTRA aber nur auf 96 Augen – und verliert
        // damit das Spiel, obwohl RE mit 144 Augen klar vorn liegt.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(144, 96),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertSame(144, $w['augen']['RE']);
        self::assertSame('KONTRA', $w['sieger'], 'Verfehlte Absage muss das Spiel kosten.');
        self::assertPositionExists($w, 'Gewonnen', 'KONTRA', 1);

        // Die Ansagepunkte der gescheiterten Partei gehen an den Gegner.
        self::assertPositionExists($w, 'Re angesagt', 'KONTRA', 2);
        self::assertPositionExists($w, 'Absage: keine 90', 'KONTRA', 1);
    }

    public function testErfuellteAbsageGewinntWieBisher(): void
    {
        // Dieselbe Ansage, diesmal bleibt KONTRA unter 90 → RE gewinnt.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(155, 85),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertSame('RE', $w['sieger']);
        self::assertPositionExists($w, 'keine 90', 'RE', 1);
    }

    public function testContraAnsageHebtDieSchwelleAuf121(): void
    {
        // 120:120 – ohne Ansage gewönne KONTRA. Mit eigener Contra-Ansage braucht
        // KONTRA jedoch 121 Augen und verfehlt sie.
        $ohneAnsage = $this->rechner->berechne(
            $this->stichfolgeMitAugen(120, 120),
            $this->teams,
            $this->ordnung,
            [],
            false,
            null,
        );
        self::assertSame('KONTRA', $ohneAnsage['sieger'], 'Ohne Ansage genügen KONTRA 120 Augen.');

        $mitAnsage = $this->rechner->berechne(
            $this->stichfolgeMitAugen(120, 120),
            $this->teams,
            $this->ordnung,
            [['sitzplatz' => 2, 'typ' => AnsageTyp::CONTRA]],
            false,
            null,
        );
        self::assertSame('RE', $mitAnsage['sieger'], 'Mit Contra-Ansage braucht KONTRA 121.');
    }

    public function testReUndContraZusammenLassenDieGrenzeBei120(): void
    {
        // TSR 7.1.2.3: Sind "Re" UND "Kontra" angesagt, gewinnt KONTRA weiterhin mit
        // dem 120. Auge – die Contra-Ansage zieht das Spiel nur dann an sich, wenn
        // Re nicht ebenfalls angesagt hat.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(120, 120),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::CONTRA],
            ],
            false,
            null,
        );

        self::assertSame('KONTRA', $w['sieger']);
        self::assertPositionExists($w, 'Gewonnen', 'KONTRA', 1);
    }

    public function testBeideVerfehlenIhreAbsageDannGewinntNiemand(): void
    {
        // TSR 7.1.3: Beide Parteien sagen "keine 90" ab, keine drückt die andere
        // unter 90 → keine Partei hat gewonnen.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(130, 110),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::CONTRA],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertNull($w['sieger'], 'Keine Partei hat gewonnen.');
        self::assertNull($this->findePosition($w, 'Gewonnen'));
        self::assertNull($this->findePosition($w, 'Gegen die Alten'));

        // Ansage- und Absagepunkte verfallen komplett.
        self::assertNull($this->findePosition($w, 'Re angesagt'));
        self::assertNull($this->findePosition($w, 'Contra angesagt'));
        self::assertNull($this->findePosition($w, 'Absage: keine 90'));

        // Es gibt trotzdem einen Empfänger für den Spielwert.
        self::assertContains($w['punkteEmpfaenger'], ['RE', 'KONTRA']);
    }

    public function testGegenAbgesagtesSchwarzGenuegtEinEinzigerStich(): void
    {
        // TSR 7.1.1.8: Sagt die Gegenpartei "schwarz" ab, gewinnt man bereits mit dem
        // ersten Stich, den man bekommt – hier mit lediglich 4 Augen.
        $stiche = [
            // KONTRA (Sitz 2) holt fast alles …
            ...$this->stichfolgeMitAugen(0, 236),
            // … aber RE (Sitz 1) bekommt einen Stich mit 4 Augen.
            $this->stichFuer(1, [4, 0, 0]),
        ];

        $w = $this->rechner->berechne(
            $stiche,
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 2, 'typ' => AnsageTyp::CONTRA],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::KEINE_NEUN],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::KEINE_SECHS],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::KEINE_DREI],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::SCHWARZ],
            ],
            false,
            null,
        );

        self::assertSame(4, $w['augen']['RE']);
        self::assertSame(1, $w['stiche']['RE']);
        self::assertSame('RE', $w['sieger'], 'Ein Stich gegen abgesagtes Schwarz genügt.');
    }

    public function testOhneAnsagenBleibtDieWertungUnveraendert(): void
    {
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(121, 119),
            $this->teams,
            $this->ordnung,
            [],
            false,
            null,
        );

        self::assertSame('RE', $w['sieger']);
        self::assertSame('RE', $w['punkteEmpfaenger']);
        self::assertPositionExists($w, 'Gewonnen', 'RE', 1);
    }

    // ── Punkte gegen Absagen der Gegenpartei (TSR 7.2.2 e/f) ─────────────────────

    public function testPunktGegenAbgesagteKeine90AbHundertzwanzigAugen(): void
    {
        // RE sagt "keine 90" ab, KONTRA erreicht 120 Augen → Zusatzpunkt für KONTRA.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(120, 120),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertSame('KONTRA', $w['sieger'], 'REs Absage ist gescheitert.');
        self::assertPositionExists($w, '120 Augen gegen keine 90', 'KONTRA', 1);
    }

    public function testKnappUnterDerMarkeGibtKeinenPunkt(): void
    {
        // KONTRA erreicht nur 116 Augen – die Absage ist zwar gescheitert (über 90),
        // die Marke von 120 aber nicht erreicht.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(124, 116),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertSame('KONTRA', $w['sieger']);
        self::assertNull($this->findePosition($w, '120 Augen gegen keine 90'));
    }

    public function testGestapelteAbsagenBringenMehrereGegenpunkte(): void
    {
        // RE sagt keine 90 und keine 60 ab; KONTRA holt 120 Augen und überbietet beide
        // Marken (120 gegen keine 90, 90 gegen keine 60).
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(120, 120),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
                ['sitzplatz' => 3, 'typ' => AnsageTyp::KEINE_SECHS],
            ],
            false,
            null,
        );

        self::assertPositionExists($w, '120 Augen gegen keine 90', 'KONTRA', 1);
        self::assertPositionExists($w, '90 Augen gegen keine 60', 'KONTRA', 1);
    }

    public function testDreissigAugenGegenAngesagtesSchwarz(): void
    {
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(208, 32),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::SCHWARZ],
            ],
            false,
            null,
        );

        // KONTRA hat Stiche gemacht → das angesagte Schwarz ist gescheitert.
        self::assertSame('KONTRA', $w['sieger']);
        self::assertPositionExists($w, '30 Augen gegen schwarz', 'KONTRA', 1);
    }

    public function testErfuellteAbsageBringtDerGegenparteiKeinenPunkt(): void
    {
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(155, 85),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertSame('RE', $w['sieger']);
        self::assertNull($this->findePosition($w, '120 Augen gegen keine 90'));
    }

    public function testGegenpunkteBleibenAuchOhneSiegerErhalten(): void
    {
        // Beide sagen "keine 90" ab und scheitern (TSR 7.1.3). RE erreicht dabei 130 Augen
        // gegen KONTRAs Absage und behält diesen Punkt, obwohl niemand gewonnen hat.
        $w = $this->rechner->berechne(
            $this->stichfolgeMitAugen(130, 110),
            $this->teams,
            $this->ordnung,
            [
                ['sitzplatz' => 1, 'typ' => AnsageTyp::RE],
                ['sitzplatz' => 1, 'typ' => AnsageTyp::KEINE_NEUN],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::CONTRA],
                ['sitzplatz' => 2, 'typ' => AnsageTyp::KEINE_NEUN],
            ],
            false,
            null,
        );

        self::assertNull($w['sieger'], 'Beide Parteien haben ihre Absage verfehlt.');
        self::assertNull($this->findePosition($w, 'Re angesagt'), 'Ansagepunkte verfallen.');
        self::assertPositionExists($w, '120 Augen gegen keine 90', 'RE', 1);
    }

    // ── Helfer ────────────────────────────────────────────────────────────────

    /**
     * Baut eine Stichfolge, in der RE und KONTRA exakt die gewünschten Augen holen.
     * Hier zählt allein die Augenverteilung, nicht die Realistik des Blattes – der
     * WertungsRechner prüft keine Kartenherkunft, deshalb dürfen Karten mehrfach fallen.
     *
     * Jeder Stich wird vom Führenden mit der Karo-Neun (Trumpf, 0 Augen) gewonnen; die
     * drei Mitspieler legen Fehlfarben, deren Augen die Zielsumme ergeben.
     *
     * @return list<list<array{sitzplatz: int, karte: Karte}>>
     */
    private function stichfolgeMitAugen(int $reAugen, int $kontraAugen): array
    {
        $stiche = [];

        foreach ([[1, $reAugen], [2, $kontraAugen]] as [$fuehrer, $ziel]) {
            foreach (array_chunk($this->augenZerlegen($ziel), 3) as $gruppe) {
                $gruppe = array_pad($gruppe, 3, 0);
                $stiche[] = $this->stichFuer($fuehrer, $gruppe);
            }
        }

        return $stiche;
    }

    /**
     * Zerlegt eine Augenzahl exakt in Kartenwerte (Ass 11 / König 4).
     * Für jede hier verwendete Zielzahl existiert eine solche Zerlegung.
     *
     * @return list<int>
     */
    private function augenZerlegen(int $ziel): array
    {
        for ($asse = 0; $asse * 11 <= $ziel; $asse++) {
            $rest = $ziel - $asse * 11;
            if ($rest % 4 !== 0) {
                continue;
            }

            return array_merge(
                array_fill(0, $asse, 11),
                array_fill(0, intdiv($rest, 4), 4),
            );
        }

        self::fail(sprintf('Augenzahl %d lässt sich nicht aus Assen und Königen bilden.', $ziel));
    }

    /**
     * Ein Stich, den $fuehrer mit der Karo-Neun gewinnt. Die drei Mitspieler legen
     * Fehlfarben mit den gewünschten Augenwerten (11 = Ass, 4 = König, 0 = Neun).
     *
     * @param list<int> $augenWerte genau drei Werte
     * @return list<array{sitzplatz: int, karte: Karte}>
     */
    private function stichFuer(int $fuehrer, array $augenWerte): array
    {
        $farben = ['KREUZ', 'PIK', 'HERZ'];
        $karte  = static fn(int $augen, string $farbe): string => $farbe . '_' . match ($augen) {
            11      => 'ASS',
            4       => 'KOENIG',
            default => 'NEUN',
        } . '_1';

        $eintraege = [[$fuehrer, 'KARO_NEUN_1']];
        foreach ($augenWerte as $i => $augen) {
            $sitz = ($fuehrer + $i) % 4 + 1;
            $eintraege[] = [$sitz, $karte($augen, $farben[$i])];
        }

        return $this->stich($eintraege);
    }

    /**
     * @param list<array{0: int, 1: string}> $eintraege  je [sitzplatz, KartenId] in Ausspielreihenfolge
     * @return list<array{sitzplatz: int, karte: Karte}>
     */
    private function stich(array $eintraege): array
    {
        return array_map(
            static fn(array $e): array => ['sitzplatz' => $e[0], 'karte' => Karte::vonId($e[1])],
            $eintraege,
        );
    }

    /** @param array<string, mixed> $w */
    private function findePosition(array $w, string $label): ?array
    {
        foreach ($w['positionen'] as $p) {
            if ($p['label'] === $label) {
                return $p;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $w */
    private function assertPositionExists(array $w, string $label, string $team, int $punkte): void
    {
        $p = $this->findePosition($w, $label);
        self::assertNotNull($p, "Position '$label' erwartet");
        self::assertSame($team, $p['team'], "Position '$label' sollte an $team gehen");
        self::assertSame($punkte, $p['punkte'], "Position '$label' sollte $punkte Punkte haben");
    }
}
