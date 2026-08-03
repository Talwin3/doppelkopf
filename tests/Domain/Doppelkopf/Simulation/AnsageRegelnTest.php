<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Simulation;

use App\Application\Doppelkopf\AnsageService;
use App\Domain\Doppelkopf\Simulation\AnsageRegeln;
use App\Enum\AnsageTyp;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

final class AnsageRegelnTest extends TestCase
{
    private AnsageRegeln $regeln;

    /** @var array<int, Team> */
    private array $teams;

    protected function setUp(): void
    {
        $this->regeln = new AnsageRegeln();
        $this->teams  = [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::RE, 4 => Team::KONTRA];
    }

    /**
     * Der Benchmark hält eigene Kopien der Timing-/Stacking-Tabellen, weil er ohne
     * Datenbank läuft. Dieser Test schlägt an, sobald sie vom Produktivpfad abweichen.
     */
    public function testTabellenStimmenMitDemAnsageServiceUeberein(): void
    {
        $reflection = new \ReflectionClass(AnsageService::class);

        self::assertSame(
            $reflection->getConstant('TIMING'),
            AnsageRegeln::TIMING,
            'AnsageRegeln::TIMING ist gegenüber AnsageService::TIMING veraltet.',
        );
        self::assertSame(
            $reflection->getConstant('VORAUSSETZUNG'),
            AnsageRegeln::VORAUSSETZUNG,
            'AnsageRegeln::VORAUSSETZUNG ist gegenüber AnsageService::VORAUSSETZUNG veraltet.',
        );
    }

    public function testReNurVomReTeamUndNurRechtzeitig(): void
    {
        self::assertTrue($this->regeln->erlaubt(AnsageTyp::RE, 1, 12, [], $this->teams));
        self::assertTrue($this->regeln->erlaubt(AnsageTyp::RE, 1, 11, [], $this->teams));
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::RE, 1, 10, [], $this->teams), 'zu spät');
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::RE, 2, 12, [], $this->teams), 'Kontra darf kein Re');
        self::assertTrue($this->regeln->erlaubt(AnsageTyp::CONTRA, 2, 12, [], $this->teams));
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::CONTRA, 1, 12, [], $this->teams));
    }

    public function testKeineDoppelteAnsageDesselbenSitzes(): void
    {
        $bisher = [['sitzplatz' => 1, 'typ' => AnsageTyp::RE]];

        self::assertFalse($this->regeln->erlaubt(AnsageTyp::RE, 1, 12, $bisher, $this->teams));
        self::assertTrue($this->regeln->erlaubt(AnsageTyp::RE, 3, 12, $bisher, $this->teams), 'Partner darf noch');
    }

    public function testAbsageBrauchtDieVorherigeStufeDesTeams(): void
    {
        self::assertFalse(
            $this->regeln->erlaubt(AnsageTyp::KEINE_NEUN, 1, 10, [], $this->teams),
            'ohne Re/Contra keine 90',
        );

        // Der Partner (Sitz 3) hat Re gesagt – das zählt fürs ganze Team.
        $mitRe = [['sitzplatz' => 3, 'typ' => AnsageTyp::RE]];
        self::assertTrue($this->regeln->erlaubt(AnsageTyp::KEINE_NEUN, 1, 10, $mitRe, $this->teams));

        // Die Gegenpartei profitiert nicht davon.
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::KEINE_NEUN, 2, 10, $mitRe, $this->teams));

        // "keine 60" setzt "keine 90" voraus, nicht Re.
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::KEINE_SECHS, 1, 9, $mitRe, $this->teams));
    }

    public function testHochzeitUndSoloSindKeineAnsagen(): void
    {
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::HOCHZEIT, 1, 12, [], $this->teams));
        self::assertFalse($this->regeln->erlaubt(AnsageTyp::SOLO, 1, 12, [], $this->teams));
    }
}
