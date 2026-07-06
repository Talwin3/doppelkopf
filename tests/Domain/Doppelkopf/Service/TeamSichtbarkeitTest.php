<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\TeamSichtbarkeit;
use App\Enum\SpielVariante;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die Fairness-Regel: Bots dürfen nur öffentlich bekannte Teams kennen.
 * Aufstellung: 1+3 = RE, 2+4 = KONTRA; Betrachter (Bot) sitzt auf Platz 2.
 */
final class TeamSichtbarkeitTest extends TestCase
{
    private const ALLE = [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::RE, 4 => Team::KONTRA];
    private const EIGEN = 2;

    private function bekannt(
        ?SpielVariante $variante = SpielVariante::NORMALSPIEL,
        bool $hochzeitAufgeloest = false,
        array $hochzeitspieler = [],
        array $kreuzDamen = [],
        array $angesagt = [],
    ): array {
        return TeamSichtbarkeit::bekannteTeams(
            self::ALLE, $variante, $hochzeitAufgeloest, $hochzeitspieler, $kreuzDamen, $angesagt, self::EIGEN,
        );
    }

    public function testNormalspielVerdecktAllesAusserEigenemTeam(): void
    {
        self::assertSame(
            [1 => null, 2 => Team::KONTRA, 3 => null, 4 => null],
            $this->bekannt(),
        );
    }

    public function testGespielteKreuzDameDecktNurDiesenSitzAuf(): void
    {
        self::assertSame(
            [1 => Team::RE, 2 => Team::KONTRA, 3 => null, 4 => null],
            $this->bekannt(kreuzDamen: [1]),
        );
    }

    public function testBeideKreuzDamenDeckenAlleTeamsAuf(): void
    {
        self::assertSame(self::ALLE, $this->bekannt(kreuzDamen: [1, 3]));
    }

    public function testAnsageDecktDenAnsagendenSitzAuf(): void
    {
        self::assertSame(
            [1 => null, 2 => Team::KONTRA, 3 => null, 4 => Team::KONTRA],
            $this->bekannt(angesagt: [4]),
        );
    }

    public function testSoloDecktAlleTeamsSofortAuf(): void
    {
        self::assertSame(self::ALLE, $this->bekannt(variante: SpielVariante::SOLO_DAMEN));
    }

    public function testHochzeitZeigtNurDenHochzeitspielerBisZurAufloesung(): void
    {
        self::assertSame(
            [1 => Team::RE, 2 => Team::KONTRA, 3 => null, 4 => null],
            $this->bekannt(variante: SpielVariante::HOCHZEIT, hochzeitspieler: [1]),
        );
    }

    public function testAufgelosteHochzeitDecktAlleTeamsAuf(): void
    {
        self::assertSame(
            self::ALLE,
            $this->bekannt(variante: SpielVariante::HOCHZEIT, hochzeitAufgeloest: true, hochzeitspieler: [1]),
        );
    }
}
