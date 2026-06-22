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

    // ── Helfer ────────────────────────────────────────────────────────────────

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
