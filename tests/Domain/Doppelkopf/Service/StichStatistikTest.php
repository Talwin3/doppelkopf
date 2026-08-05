<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\StichStatistik;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * {@see StichStatistik} füttert zwei Dinge: die verdeckten Stich-Stapel am Tisch und –
 * über {@see StichStatistik::augenFuerTeam()} – die Ansage-Entscheidung der Bots. Letzteres
 * ist das Produktiv-Pendant zu {@see \App\Domain\Doppelkopf\Simulation\Partie::erspielteAugen()};
 * beide müssen dasselbe zählen, sonst messen Benchmark und Tisch verschiedene Bots.
 */
final class StichStatistikTest extends TestCase
{
    private StichStatistik $statistik;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->statistik = new StichStatistik(new StichGewinner());
        $this->ordnung   = new NormalspielTrumpfOrdnung();
    }

    /**
     * @param array<int, string> $karten Sitzplatz → Karten-ID, in Spielreihenfolge
     * @return list<array{sitzplatz: int, karte: Karte}>
     */
    private function stich(array $karten): array
    {
        $eintraege = [];
        foreach ($karten as $sitz => $id) {
            $eintraege[] = ['sitzplatz' => $sitz, 'karte' => Karte::vonId($id)];
        }

        return $eintraege;
    }

    /** Kreuz-Ass holt den Fehlfarbenstich: 11 + 10 + 4 + 0 = 25 Augen für Sitz 1. */
    private function fehlfarbenStich(): array
    {
        return $this->stich([
            1 => 'KREUZ_ASS_1', 2 => 'KREUZ_ZEHN_1', 3 => 'KREUZ_KOENIG_1', 4 => 'KREUZ_NEUN_1',
        ]);
    }

    /** Karo-Ass ist Trumpf und übersticht die Fehlfarbe: 11 + 11 + 4 + 0 = 26 Augen für Sitz 2. */
    private function abgestochenerStich(): array
    {
        return $this->stich([
            1 => 'PIK_ASS_1', 2 => 'KARO_ASS_1', 3 => 'PIK_KOENIG_1', 4 => 'PIK_NEUN_1',
        ]);
    }

    public function testGewonneneSticheZaehltAnzahlUndAugen(): void
    {
        $ergebnis = $this->statistik->gewonneneStiche(
            [$this->fehlfarbenStich(), $this->abgestochenerStich()],
            $this->ordnung,
            false,
        );

        self::assertSame(['anzahl' => 1, 'augen' => 25], $ergebnis[1]);
        self::assertSame(['anzahl' => 1, 'augen' => 26], $ergebnis[2]);
        self::assertArrayNotHasKey(3, $ergebnis);
    }

    public function testAugenFuerTeamSummiertBeidePartner(): void
    {
        $teams = [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::RE, 4 => Team::KONTRA];
        $stiche = [$this->fehlfarbenStich(), $this->abgestochenerStich()];

        self::assertSame(25, $this->statistik->augenFuerTeam($stiche, $teams, Team::RE, $this->ordnung, false));
        self::assertSame(26, $this->statistik->augenFuerTeam($stiche, $teams, Team::KONTRA, $this->ordnung, false));
    }

    /** Vor der Klärung einer Hochzeit sind Teams unbekannt – dann zählt nichts mit. */
    public function testUnbekannteTeamsZaehlenNichtMit(): void
    {
        $teams = [1 => null, 2 => null, 3 => null, 4 => null];

        self::assertSame(
            0,
            $this->statistik->augenFuerTeam([$this->fehlfarbenStich()], $teams, Team::RE, $this->ordnung, false),
        );
    }
}
