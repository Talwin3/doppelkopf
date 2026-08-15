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
use App\Domain\Doppelkopf\ValueObject\FortgeschritteneRegeln;
use App\Enum\BotStaerke;
use PHPUnit\Framework\TestCase;

/**
 * Die einzeln abschaltbaren Regeln sind Messwerkzeug für den Bot-Benchmark: nur wenn jede
 * Regel exakt eine Abweichung von der Anfänger-Engine kapselt, misst eine Ablation auch
 * wirklich diese eine Regel.
 *
 * Kernprüfung: mit `FortgeschritteneRegeln::keine()` muss die fortgeschrittene Engine über
 * ganze Partien hinweg Karte für Karte dasselbe spielen wie die {@see BotHeuristik} – die
 * gepaarte Turnierdifferenz muss also exakt 0,0 sein.
 */
final class FortgeschritteneRegelnTest extends TestCase
{
    private TurnierLauf $turnier;

    protected function setUp(): void
    {
        $stichGewinner = new StichGewinner();

        $this->turnier = new TurnierLauf(
            new SpielSimulator(
                $stichGewinner,
                new WertungsRechner($stichGewinner),
                new AnsageRegeln(),
                new EvidenzDiagnose(new TeamEvidenz(new RolloutPolitik())),
                new AnsageHeuristik(),
            ),
            new KartenGeber(),
            new TrumpfOrdnungFactory(),
        );
    }

    private function strategie(BotStaerke $staerke, ?FortgeschritteneRegeln $regeln = null): SimulierteStrategie
    {
        return new EngineStrategie(
            $staerke,
            new BotHeuristik(),
            new FortgeschritteneHeuristik(),
            new ProfiHeuristik(new StichGewinner(), new WertungsRechner(new StichGewinner()), new EndspielLoeser(new StichGewinner(), new RolloutPolitik()), new RolloutPolitik()),
            new AnsageHeuristik(),
            20,
            0.2,
            false, // Ansagen aus: verglichen wird ausschließlich die Kartenwahl.
            $regeln,
        );
    }

    public function testOhneRegelnSpieltFortgeschrittenExaktWieAnfaenger(): void
    {
        $ergebnis = $this->turnier->spiele(
            $this->strategie(BotStaerke::FORTGESCHRITTEN, FortgeschritteneRegeln::keine()),
            $this->strategie(BotStaerke::ANFAENGER),
            25,
            seed: 7,
        );

        self::assertGreaterThan(0, $ergebnis->anzahlBlaetter());
        self::assertSame(0.0, $ergebnis->mittelwert());
        self::assertSame(0.0, $ergebnis->standardabweichung());
    }

    public function testMitAllenRegelnWeichtDieKartenwahlAb(): void
    {
        $ergebnis = $this->turnier->spiele(
            $this->strategie(BotStaerke::FORTGESCHRITTEN, FortgeschritteneRegeln::alle()),
            $this->strategie(BotStaerke::ANFAENGER),
            25,
            seed: 7,
        );

        // Ohne diese Absicherung könnte ein kaputter Schalter alle Regeln stilllegen und
        // der Test oben wäre trotzdem grün.
        self::assertNotSame(0.0, $ergebnis->standardabweichung());
    }

    /**
     * Drei Regeln hängen daran, dass ein *öffentlich bekannter* Gegner in einer Farbe
     * nachweislich frei ist. Seit der Fairness-Korrektur (nur öffentlich sichtbare Teams)
     * ist das selten: gemessen über 1500 Blätter greifen sie in 0,2 % / 0,3 % / 0,4 % der
     * Blätter. Über 40 Blätter feuern sie gar nicht – ihre Schalter werden deshalb gezielt
     * in `FortgeschritteneHeuristikTest` geprüft statt hier über ganze Partien.
     */
    public function testJedeEinzelneRegelVeraendertDasSpielGegenueberKeiner(): void
    {
        $selten = ['ass-nur-sicher', 'sicheres-abwerfen', 'schmier-sicherheit'];

        $referenz = $this->turnier->spiele(
            $this->strategie(BotStaerke::FORTGESCHRITTEN, FortgeschritteneRegeln::keine()),
            $this->strategie(BotStaerke::ANFAENGER),
            40,
            seed: 11,
        )->differenzen;

        foreach (array_keys(FortgeschritteneRegeln::BESCHREIBUNGEN) as $regel) {
            if (in_array($regel, $selten, true)) {
                continue;
            }

            $mitRegel = $this->turnier->spiele(
                $this->strategie(BotStaerke::FORTGESCHRITTEN, FortgeschritteneRegeln::nur($regel)),
                $this->strategie(BotStaerke::ANFAENGER),
                40,
                seed: 11,
            )->differenzen;

            self::assertNotSame(
                $referenz,
                $mitRegel,
                sprintf('Regel "%s" hat keinerlei Wirkung – Schalter oder Regel ist tot.', $regel),
            );
        }
    }

    public function testListeAlleUndKeine(): void
    {
        self::assertSame(FortgeschritteneRegeln::alle()->alsFlags(), FortgeschritteneRegeln::ausListe('alle')->alsFlags());
        self::assertSame(FortgeschritteneRegeln::alle()->alsFlags(), FortgeschritteneRegeln::ausListe('')->alsFlags());
        self::assertNotContains(true, FortgeschritteneRegeln::ausListe('keine')->alsFlags());
    }

    public function testListeWaehltAusUndAb(): void
    {
        $nur = FortgeschritteneRegeln::ausListe('stich-lohnt-sich, sichere-stiche');
        self::assertTrue($nur->stichLohntSich);
        self::assertTrue($nur->sichereStiche);
        self::assertFalse($nur->assNurSicher);
        self::assertSame('nur sichere-stiche, stich-lohnt-sich', $nur->beschreibung());

        $ohne = FortgeschritteneRegeln::ausListe('-stich-lohnt-sich');
        self::assertFalse($ohne->stichLohntSich);
        self::assertTrue($ohne->sichereStiche);
        self::assertSame('ohne stich-lohnt-sich', $ohne->beschreibung());
    }

    public function testGemischteListeWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FortgeschritteneRegeln::ausListe('stich-lohnt-sich,-sichere-stiche');
    }

    public function testUnbekannteRegelWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FortgeschritteneRegeln::ausListe('gibt-es-nicht');
    }
}
