<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\KartenGeber;
use App\Domain\Doppelkopf\Service\EndspielLoeser;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Service\RolloutPolitik;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\TeamEvidenz;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\Service\WertungsRechner;
use App\Domain\Doppelkopf\Simulation\AnsageRegeln;
use App\Domain\Doppelkopf\Simulation\EngineStrategie;
use App\Domain\Doppelkopf\Simulation\SimulierteStrategie;
use App\Domain\Doppelkopf\Simulation\EvidenzDiagnose;
use App\Domain\Doppelkopf\Simulation\SpielSimulator;
use App\Domain\Doppelkopf\Simulation\TurnierLauf;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\BotStaerke;
use App\Enum\SpielVariante;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Der Self-Play-Benchmark ist Messwerkzeug – er muss selbst korrekt sein, sonst
 * misst er später falsch. Geprüft wird: vollständiger Spielverlauf, saubere
 * Abrechnung (Nullsummenspiel, 240 Augen) und Symmetrie des Turnier-Verfahrens.
 */
final class SpielSimulatorTest extends TestCase
{
    private SpielSimulator $simulator;
    private TrumpfOrdnungFactory $trumpfOrdnungFactory;
    private KartenGeber $kartenGeber;

    protected function setUp(): void
    {
        $stichGewinner = new StichGewinner();

        $this->simulator = new SpielSimulator(
            $stichGewinner,
            new WertungsRechner($stichGewinner),
            new AnsageRegeln(),
            new EvidenzDiagnose(new TeamEvidenz(new RolloutPolitik())),
            new AnsageHeuristik(),
        );
        $this->trumpfOrdnungFactory = new TrumpfOrdnungFactory();
        $this->kartenGeber          = new KartenGeber();
    }

    private function strategie(BotStaerke $staerke, int $rollouts = 20): SimulierteStrategie
    {
        return new EngineStrategie(
            $staerke,
            new BotHeuristik(),
            new FortgeschritteneHeuristik(),
            new ProfiHeuristik(new StichGewinner(), new WertungsRechner(new StichGewinner()), new EndspielLoeser(new StichGewinner(), new RolloutPolitik()), new RolloutPolitik()),
            new AnsageHeuristik(),
            $rollouts,
            0.2,
        );
    }

    /**
     * Erstes Blatt ab diesem Seed, das keine stille Hochzeit ist.
     *
     * @return array{0: array<int, Karte[]>, 1: array<int, Team>}
     */
    private function blatt(int $seed): array
    {
        for ($versuch = 0; $versuch < 50; $versuch++) {
            mt_srand($seed + $versuch);
            $austeilung = $this->kartenGeber->austeilen();
            $blatt = [1 => $austeilung[0], 2 => $austeilung[1], 3 => $austeilung[2], 4 => $austeilung[3]];

            $teams = SpielSimulator::teamsAusKreuzDamen($blatt);
            if ($teams !== null) {
                return [$blatt, $teams];
            }
        }

        self::fail('Kein Blatt ohne stille Hochzeit gefunden.');
    }

    public function testSpieltEineVollstaendigePartieUndRechnetSieAb(): void
    {
        [$blatt, $teams] = $this->blatt(1);

        $strategie = $this->strategie(BotStaerke::FORTGESCHRITTEN);
        $ergebnis  = $this->simulator->spiele(
            $blatt,
            array_fill(1, 4, $strategie),
            $teams,
            $this->trumpfOrdnungFactory->fuer(SpielVariante::NORMALSPIEL),
        );

        self::assertSame(240, array_sum($ergebnis->augen), 'Im Spiel stecken immer 240 Augen.');
        self::assertSame(12, $ergebnis->wertung['stiche'][Team::RE->value] + $ergebnis->wertung['stiche'][Team::KONTRA->value]);
        self::assertSame(0, array_sum($ergebnis->punkteProSitzplatz), 'Turnierpunkte sind ein Nullsummenspiel.');
        self::assertCount(4, $ergebnis->punkteProSitzplatz);
    }

    public function testErkenntStilleHochzeit(): void
    {
        $beideKreuzDamen = [
            1 => [Karte::vonId('KREUZ_DAME_1'), Karte::vonId('KREUZ_DAME_2')],
            2 => [Karte::vonId('PIK_ASS_1'), Karte::vonId('PIK_ASS_2')],
            3 => [Karte::vonId('HERZ_ASS_1'), Karte::vonId('HERZ_ASS_2')],
            4 => [Karte::vonId('KARO_ASS_1'), Karte::vonId('KARO_ASS_2')],
        ];

        self::assertNull(SpielSimulator::teamsAusKreuzDamen($beideKreuzDamen));
    }

    public function testTeamsFolgenDenKreuzDamen(): void
    {
        [, $teams] = $this->blatt(7);

        $reSitze = array_keys(array_filter($teams, fn(Team $t) => $t === Team::RE));
        self::assertCount(2, $reSitze, 'Ohne stille Hochzeit sind genau zwei Spieler RE.');
    }

    /**
     * Kernprüfung des Duplikat-Verfahrens: Spielt dieselbe deterministische Strategie
     * auf beiden Seiten, muss die gepaarte Differenz **exakt** null sein. Jede
     * Abweichung wäre ein Sitzplatz-Bias oder ein Buchungsfehler im Turnier-Aufbau.
     */
    public function testGleicheStrategieErgibtExaktNull(): void
    {
        $turnier = new TurnierLauf($this->simulator, $this->kartenGeber, $this->trumpfOrdnungFactory);

        $ergebnis = $turnier->spiele(
            $this->strategie(BotStaerke::FORTGESCHRITTEN),
            $this->strategie(BotStaerke::FORTGESCHRITTEN),
            12,
            seed: 4242,
        );

        self::assertGreaterThan(0, $ergebnis->anzahlBlaetter());
        self::assertSame(0.0, $ergebnis->mittelwert());
        self::assertSame(0.0, $ergebnis->standardabweichung());
        self::assertSame($ergebnis->ansagenA, $ergebnis->ansagenB);
    }

    /**
     * Der Anker gilt auch im Team-Modus: Zwei identische deterministische Strategien
     * müssen sich exakt aufheben, egal ob nach Sitzplatz oder nach Partei verteilt wird.
     * Sichert, dass die Spiegelung den RE-Vorteil wirklich herauskürzt – ohne sie würde
     * hier ein deutlicher Überschuss für die RE-Seite stehen bleiben.
     */
    public function testTeamModusHebtGleicheStrategieEbenfallsAuf(): void
    {
        $turnier = new TurnierLauf($this->simulator, $this->kartenGeber, $this->trumpfOrdnungFactory);

        $ergebnis = $turnier->spiele(
            $this->strategie(BotStaerke::FORTGESCHRITTEN),
            $this->strategie(BotStaerke::FORTGESCHRITTEN),
            12,
            seed: 4242,
            nachTeam: true,
        );

        self::assertGreaterThan(0, $ergebnis->anzahlBlaetter());
        self::assertSame(0.0, $ergebnis->mittelwert());
        self::assertSame(0.0, $ergebnis->standardabweichung());
        self::assertSame($ergebnis->ansagenA, $ergebnis->ansagenB);
    }

    public function testTurnierIstMitGleichemSeedReproduzierbar(): void
    {
        $turnier = new TurnierLauf($this->simulator, $this->kartenGeber, $this->trumpfOrdnungFactory);

        $lauf = fn(): array => $turnier->spiele(
            $this->strategie(BotStaerke::FORTGESCHRITTEN),
            $this->strategie(BotStaerke::ANFAENGER),
            10,
            seed: 99,
        )->differenzen;

        self::assertSame($lauf(), $lauf());
    }
}
