<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Simulation\Partie;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Das Augen-Konto der {@see Partie} ist die Benchmark-Entsprechung der Stich-Stapel am
 * Tisch. Es liefert den ersten Summanden der Essener Ansage-Formel („bis zur Ansage
 * erzielte Augenzahl plus Summe der Erwartungswerte"), muss also stimmen – sonst sagt
 * der gemessene Bot nach anderen Kriterien an als der am Tisch.
 */
final class PartieTest extends TestCase
{
    /** @param string[] $ids */
    private function karten(array $ids): array
    {
        return array_map(static fn(string $id) => Karte::vonId($id), $ids);
    }

    private function partie(): Partie
    {
        // Nur die im Test gespielten Karten müssen auf der Hand liegen.
        return new Partie(
            [
                1 => $this->karten(['KREUZ_ASS_1', 'PIK_ASS_1']),
                2 => $this->karten(['KREUZ_ZEHN_1', 'PIK_ZEHN_1']),
                3 => $this->karten(['KREUZ_KOENIG_1', 'PIK_KOENIG_1']),
                4 => $this->karten(['KREUZ_NEUN_1', 'PIK_NEUN_1']),
            ],
            [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::RE, 4 => Team::KONTRA],
            new NormalspielTrumpfOrdnung(),
        );
    }

    /** @param array<int, string> $karten Sitzplatz → Karten-ID, in Spielreihenfolge */
    private function spieleStich(Partie $partie, array $karten, int $gewinner): void
    {
        foreach ($karten as $sitz => $id) {
            $partie->spieleKarte($sitz, Karte::vonId($id));
        }
        self::assertTrue($partie->stichVollstaendig());
        $partie->schliesseStich($gewinner);
    }

    public function testVorDemErstenStichIstDasKontoLeer(): void
    {
        $partie = $this->partie();

        self::assertSame(0, $partie->erspielteAugen(Team::RE));
        self::assertSame(0, $partie->erspielteAugen(Team::KONTRA));
        self::assertSame(0, $partie->erspielteAugen(null));
    }

    public function testStichAugenGehenAnDiePartieDesGewinners(): void
    {
        $partie = $this->partie();

        // Ass 11 + Zehn 10 + König 4 + Neun 0 = 25 Augen, geholt von Sitz 1 (RE).
        $this->spieleStich($partie, [
            1 => 'KREUZ_ASS_1', 2 => 'KREUZ_ZEHN_1', 3 => 'KREUZ_KOENIG_1', 4 => 'KREUZ_NEUN_1',
        ], 1);

        self::assertSame(25, $partie->erspielteAugen(Team::RE));
        self::assertSame(0, $partie->erspielteAugen(Team::KONTRA));
    }

    public function testKontenAddierenUeberMehrereStiche(): void
    {
        $partie = $this->partie();

        $this->spieleStich($partie, [
            1 => 'KREUZ_ASS_1', 2 => 'KREUZ_ZEHN_1', 3 => 'KREUZ_KOENIG_1', 4 => 'KREUZ_NEUN_1',
        ], 1);
        $this->spieleStich($partie, [
            1 => 'PIK_ASS_1', 2 => 'PIK_ZEHN_1', 3 => 'PIK_KOENIG_1', 4 => 'PIK_NEUN_1',
        ], 2);

        self::assertSame(25, $partie->erspielteAugen(Team::RE));
        self::assertSame(25, $partie->erspielteAugen(Team::KONTRA));
    }
}
