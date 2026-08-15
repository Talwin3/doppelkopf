<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\EndspielLoeser;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\RolloutPolitik;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\WertungsRechner;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\SpielVariante;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * PIMC-Engine. Da die Determinisierung zufällig ist, werden Szenarien gewählt,
 * in denen die beste Karte über alle Welten hinweg eindeutig ist.
 */
final class ProfiHeuristikTest extends TestCase
{
    private ProfiHeuristik $profi;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->profi   = new ProfiHeuristik(new StichGewinner(), new WertungsRechner(new StichGewinner()), new EndspielLoeser(new StichGewinner(), new RolloutPolitik()), new RolloutPolitik());
        $this->ordnung = new NormalspielTrumpfOrdnung();
    }

    private function k(string $id): Karte
    {
        return Karte::vonId($id);
    }

    public function testStichtWertvollenStichAlsLetzterSpieler(): void
    {
        // Bot (Sitz 1, KONTRA) ist letzter Spieler. Sitz 2 (RE) führt mit Kreuz-Ass;
        // 25 Augen liegen im Stich. Bot kann mit Karo-Neun (Trumpf) stechen oder Pik
        // abwerfen. Restkarten sind augenlos → Stechen sichert eindeutig 25 Augen.
        $offenerStich = [
            2 => $this->k('KREUZ_ASS_1'),
            3 => $this->k('KREUZ_ZEHN_1'),
            4 => $this->k('KREUZ_KOENIG_1'),
        ];
        $eigeneHand = [$this->k('KARO_NEUN_1'), $this->k('PIK_NEUN_1')];
        $ungesehene = [$this->k('PIK_NEUN_2'), $this->k('HERZ_NEUN_1'), $this->k('KARO_NEUN_2')];

        $g = SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung);

        $gewaehlt = $this->profi->entscheide(
            erlaubte: $eigeneHand,
            eigeneHand: $eigeneHand,
            eigenerSitz: 1,
            eigenesTeam: Team::KONTRA,
            offenerStich: $offenerStich,
            restHandGroessen: [2 => 1, 3 => 1, 4 => 1],
            ungesehene: $ungesehene,
            gedaechtnis: $g,
            // SOLO nur, um die bekannten Teams für die Wertung zu nutzen (Sitz 2 = RE).
            variante: SpielVariante::SOLO_DAMEN,
            bekannteTeams: [1 => Team::KONTRA, 2 => Team::RE, 3 => Team::KONTRA, 4 => Team::RE],
            kreuzDameGespieltVon: [],
            ordnung: $this->ordnung,
            zweiteDulle: false,
            rollouts: 20,
        );

        self::assertSame('KARO_NEUN_1', $gewaehlt->id(), 'Bot sollte den 25-Augen-Stich mit Trumpf holen.');
    }

    /**
     * Der Kern der Bewertung in Turnierpunkten: Augengleichheit, aber ein Doppelkopf
     * (Stich ≥ 40 Augen) mehr.
     *
     * Bot (Sitz 1, KONTRA) ist letzter Spieler; im Stich liegen 32 Augen, die er mit
     * jedem seiner beiden Karo-Trümpfe holt. Danach spielt er den letzten Stich an und
     * gewinnt ihn ebenfalls – die Mitspieler haben nur augenlose Fehlfarben.
     *  - Karo-Neun jetzt → Stiche mit 32 und 10 Augen,
     *  - Karo-Zehn jetzt → Stiche mit 42 und 0 Augen.
     * Beide Wege bringen der Partei **exakt 42 Augen**; nur der zweite ist ein Doppelkopf
     * und damit einen Turnierpunkt wert. Der alte Augen-Maßstab kann hier nicht
     * unterscheiden und nimmt die erste Karte.
     */
    public function testHoltDenDoppelkopfBeiGleicherAugenzahl(): void
    {
        $offenerStich = [
            2 => $this->k('KREUZ_ASS_1'),   // 11
            3 => $this->k('KREUZ_ASS_2'),   // 11
            4 => $this->k('KREUZ_ZEHN_1'),  // 10  → 32 Augen im Stich
        ];
        $eigeneHand = [$this->k('KARO_NEUN_1'), $this->k('KARO_ZEHN_1')];
        // Je eine augenlose Fehlfarbe für die drei anderen – niemand kann den letzten
        // Stich des Bots überbieten, egal wie die Determinisierung sie verteilt.
        $ungesehene = [$this->k('PIK_NEUN_1'), $this->k('HERZ_NEUN_1'), $this->k('KREUZ_NEUN_1')];

        $g = SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung);

        $argumente = [
            'erlaubte'             => $eigeneHand,
            'eigeneHand'           => $eigeneHand,
            'eigenerSitz'          => 1,
            'eigenesTeam'          => Team::KONTRA,
            'offenerStich'         => $offenerStich,
            'restHandGroessen'     => [2 => 1, 3 => 1, 4 => 1],
            'ungesehene'           => $ungesehene,
            'gedaechtnis'          => $g,
            // SOLO nur, um die Teams festzunageln (der Bot steht allein gegen drei).
            'variante'             => SpielVariante::SOLO_DAMEN,
            'bekannteTeams'        => [1 => Team::KONTRA, 2 => Team::RE, 3 => Team::RE, 4 => Team::RE],
            'kreuzDameGespieltVon' => [],
            'ordnung'              => $this->ordnung,
            'zweiteDulle'          => false,
            'rollouts'             => 8,
        ];

        self::assertSame(
            'KARO_ZEHN_1',
            $this->profi->entscheide(...[...$argumente, 'bewertePunkte' => true])->id(),
            'Die Punkte-Bewertung muss bei gleicher Augenzahl den Doppelkopf mitnehmen.',
        );

        self::assertSame(
            'KARO_NEUN_1',
            $this->profi->entscheide(...$argumente)->id(),
            'Der Augen-Maßstab (Default) sieht hier keinen Unterschied – das ist genau die Lücke.',
        );
    }

    /**
     * Die Bewertungs-Diagnose ist ein Messgerät, kein Hebel: sie schreibt beide Maßstäbe je
     * Welt mit, darf die Kartenwahl aber nicht anfassen. Geprüft mit gesetztem Zufallsstrom,
     * sonst würden schon unterschiedliche Determinisierungen den Vergleich erklären.
     */
    public function testBewertungsDiagnoseAendertDieWahlNicht(): void
    {
        // Dieselbe Stellung wie im Doppelkopf-Test: die Teams stehen fest (Solo), sonst
        // verwirft die Engine mangels Kreuz-Damen jede Welt als 1-gegen-3 und bewertet nichts.
        $offenerStich = [
            2 => $this->k('KREUZ_ASS_1'),
            3 => $this->k('KREUZ_ASS_2'),
            4 => $this->k('KREUZ_ZEHN_1'),
        ];
        $eigeneHand = [$this->k('KARO_NEUN_1'), $this->k('KARO_ZEHN_1')];
        $ungesehene = [$this->k('PIK_NEUN_1'), $this->k('HERZ_NEUN_1'), $this->k('KREUZ_NEUN_1')];

        $argumente = [
            'erlaubte'             => $eigeneHand,
            'eigeneHand'           => $eigeneHand,
            'eigenerSitz'          => 1,
            'eigenesTeam'          => Team::KONTRA,
            'offenerStich'         => $offenerStich,
            'restHandGroessen'     => [2 => 1, 3 => 1, 4 => 1],
            'ungesehene'           => $ungesehene,
            'gedaechtnis'          => SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung),
            'variante'             => SpielVariante::SOLO_DAMEN,
            'bekannteTeams'        => [1 => Team::KONTRA, 2 => Team::RE, 3 => Team::RE, 4 => Team::RE],
            'kreuzDameGespieltVon' => [],
            'ordnung'              => $this->ordnung,
            'zweiteDulle'          => false,
            'rollouts'             => 20,
        ];

        mt_srand(20260814);
        $ohne = $this->profi->entscheide(...$argumente)->id();
        self::assertSame(0, $this->profi->bewertungsStatistik->zuege, 'Ohne Schalter bleibt der Zähler leer.');

        mt_srand(20260814);
        $mit = $this->profi->entscheide(...[...$argumente, 'bewertungsDiagnose' => true])->id();

        self::assertSame($ohne, $mit, 'Die Diagnose darf die Kartenwahl nicht verschieben.');
        self::assertSame(1, $this->profi->bewertungsStatistik->zuege);
        self::assertSame(2.0, $this->profi->bewertungsStatistik->kandidatenSchnitt());
        self::assertGreaterThan(1.0, $this->profi->bewertungsStatistik->weltenSchnitt(), 'Es müssen echte Welten ausgewertet worden sein.');
    }

    /**
     * Hebel 3, erster Schritt: wer im Normalspiel „Re" angesagt hat, hält eine Kreuz-Dame –
     * wer „Contra" angesagt hat, keine. Beides ist über {@see \App\Domain\Doppelkopf\Service\TeamSichtbarkeit}
     * öffentlich bekannt und wird von der Determinisierung bisher ignoriert.
     *
     * Geprüft wird die Wirkung an der Statistik: abgeschaltet zieht die Engine Welten,
     * die dem bekannten Team widersprechen; eingeschaltet ist die Widerspruchsquote null.
     * Seit dem 15.08.2026 ist die Nebenbedingung Default (gemessen +0,35 Punkte).
     *
     * @param array<int, mixed> $zusatz
     */
    private function argumenteMitBekanntenTeams(array $zusatz = []): array
    {
        // Bot sitzt auf 1 und ist KONTRA (hält keine Kreuz-Dame). Sitz 2 hat Re angesagt und
        // ist damit öffentlich RE, Sitz 3 hat Contra angesagt und ist öffentlich KONTRA.
        // Sitz 4 bleibt verdeckt. Beide Kreuz-Damen sind noch ungesehen.
        $eigeneHand = [$this->k('PIK_ASS_1'), $this->k('PIK_ZEHN_1'), $this->k('HERZ_ASS_1')];
        $ungesehene = [
            $this->k('KREUZ_DAME_1'), $this->k('KREUZ_DAME_2'),
            $this->k('PIK_KOENIG_1'), $this->k('PIK_NEUN_1'),
            $this->k('HERZ_KOENIG_1'), $this->k('HERZ_NEUN_1'),
            $this->k('KREUZ_ASS_1'), $this->k('KREUZ_NEUN_1'),
            $this->k('KARO_ASS_1'),
        ];

        return [...[
            'erlaubte'             => $eigeneHand,
            'eigeneHand'           => $eigeneHand,
            'eigenerSitz'          => 1,
            'eigenesTeam'          => Team::KONTRA,
            'offenerStich'         => [],
            'restHandGroessen'     => [2 => 3, 3 => 3, 4 => 3],
            'ungesehene'           => $ungesehene,
            'gedaechtnis'          => SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung),
            'variante'             => SpielVariante::NORMALSPIEL,
            'bekannteTeams'        => [1 => Team::KONTRA, 2 => Team::RE, 3 => Team::KONTRA, 4 => null],
            'kreuzDameGespieltVon' => [],
            'ordnung'              => $this->ordnung,
            'zweiteDulle'          => false,
            'rollouts'             => 40,
        ], ...$zusatz];
    }

    public function testOhneNebenbedingungEntstehenWeltenGegenDasBekannteTeam(): void
    {
        mt_srand(20260815);
        $this->profi->entscheide(...$this->argumenteMitBekanntenTeams(['teamNebenbedingung' => false]));

        $s = $this->profi->statistik;
        self::assertGreaterThan(0, $s->weltenMitTeamFakten, 'Es liegen bekannte Teams vor.');
        self::assertGreaterThan(
            0,
            $s->weltenMitTeamWiderspruch,
            'Ohne Nebenbedingung müssen Welten entstehen, die dem bekannten Team widersprechen.',
        );
    }

    public function testMitNebenbedingungWidersprichtKeineWeltMehr(): void
    {
        mt_srand(20260815);
        $this->profi->entscheide(...$this->argumenteMitBekanntenTeams(['teamNebenbedingung' => true]));

        $s = $this->profi->statistik;
        self::assertGreaterThan(0, $s->weltenMitTeamFakten);
        self::assertSame(0, $s->weltenMitTeamWiderspruch, 'Mit Nebenbedingung darf es keinen Widerspruch geben.');
    }

    public function testNebenbedingungGiltNichtImSolo(): void
    {
        // Im Solo sagt die Kreuz-Dame nichts über die Partei – die Nebenbedingung muss
        // dort schweigen, sonst würden völlig legale Welten verworfen.
        mt_srand(20260815);
        $this->profi->entscheide(...$this->argumenteMitBekanntenTeams([
            'variante'            => SpielVariante::SOLO_DAMEN,
            'teamNebenbedingung'  => true,
        ]));

        self::assertSame(0, $this->profi->statistik->weltenMitTeamFakten, 'Im Solo gibt es keine Kreuz-Damen-Fakten.');
    }

    public function testGibtImmerEineErlaubteKarteZurueck(): void
    {
        $eigeneHand = [$this->k('KREUZ_ASS_1'), $this->k('KARO_DAME_1'), $this->k('PIK_NEUN_1')];
        $ungesehene = [
            $this->k('KREUZ_ZEHN_1'), $this->k('KREUZ_KOENIG_1'), $this->k('PIK_ASS_1'),
            $this->k('HERZ_KOENIG_1'), $this->k('KARO_ASS_1'), $this->k('PIK_ZEHN_1'),
            $this->k('HERZ_NEUN_1'), $this->k('KARO_NEUN_1'), $this->k('PIK_KOENIG_1'),
        ];
        $g = SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung);

        $gewaehlt = $this->profi->entscheide(
            erlaubte: $eigeneHand,
            eigeneHand: $eigeneHand,
            eigenerSitz: 1,
            eigenesTeam: Team::RE,
            offenerStich: [],
            restHandGroessen: [2 => 3, 3 => 3, 4 => 3],
            ungesehene: $ungesehene,
            gedaechtnis: $g,
            variante: SpielVariante::NORMALSPIEL,
            bekannteTeams: [1 => Team::RE, 2 => null, 3 => null, 4 => null],
            kreuzDameGespieltVon: [],
            ordnung: $this->ordnung,
            zweiteDulle: false,
            rollouts: 15,
        );

        self::assertContains($gewaehlt->id(), array_map(fn(Karte $k) => $k->id(), $eigeneHand));
    }

    // ── Ansage-Aussichten ───────────────────────────────────────────────────
    //
    // Endstellungen, deren Ausgang in **jeder** Welt gleich ist – nur so lässt sich eine
    // Wahrscheinlichkeit exakt gegen 1,0 bzw. 0,0 prüfen, obwohl die Determinisierung
    // zufällig verteilt.

    /** @param Karte[] $eigeneHand @param Karte[] $ungesehene */
    private function aussichtenFuerEndstellung(array $eigeneHand, array $ungesehene): \App\Domain\Doppelkopf\ValueObject\AnsageAussichten
    {
        return $this->profi->aussichten(
            kandidaten: [AnsageTyp::RE, AnsageTyp::KEINE_NEUN, AnsageTyp::SCHWARZ],
            eigeneHand: $eigeneHand,
            eigenerSitz: 1,
            eigenesTeam: Team::RE,
            offenerStich: [],
            restHandGroessen: [2 => 2, 3 => 2, 4 => 2],
            ungesehene: $ungesehene,
            gedaechtnis: SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung),
            // Solo: Sitz 1 allein gegen den Rest – jeder fremde Stich fällt an KONTRA.
            variante: SpielVariante::SOLO_DAMEN,
            bekannteTeams: [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::KONTRA, 4 => Team::KONTRA],
            kreuzDameGespieltVon: [],
            ordnung: $this->ordnung,
            zweiteDulle: false,
            solistSitzplatz: 1,
            rollouts: 15,
        );
    }

    /**
     * Bot hält beide Dullen, alle anderen nur augenlose Fehlfarben: Er zieht beide Stiche,
     * die Gegenpartei bekommt weder Stich noch Auge. „Schwarz" und „keine 90" gehen damit
     * in jeder Welt auf.
     */
    public function testAussichtenErkennenDieSichereAbsage(): void
    {
        $aussichten = $this->aussichtenFuerEndstellung(
            [$this->k('HERZ_ZEHN_1'), $this->k('HERZ_ZEHN_2')],
            [
                $this->k('KREUZ_NEUN_1'), $this->k('KREUZ_NEUN_2'),
                $this->k('PIK_NEUN_1'), $this->k('PIK_NEUN_2'),
                $this->k('HERZ_NEUN_1'), $this->k('HERZ_NEUN_2'),
            ],
        );

        self::assertFalse($aussichten->leer());
        self::assertSame(1.0, $aussichten->sicherheit(AnsageTyp::SCHWARZ));
        self::assertSame(1.0, $aussichten->sicherheit(AnsageTyp::KEINE_NEUN));
    }

    /**
     * Umgekehrt: Der Bot hat nur zwei augenlose Kreuz-Neunen, jede fremde Karte sticht sie.
     * Er verliert beide Stiche – „schwarz" geht in keiner Welt auf, „keine 90" dagegen
     * schon (die Gegenpartei holt nur 50 Augen). Genau diese Unterscheidung soll die
     * Schätzung leisten; die Trumpfzahl konnte sie nie treffen.
     */
    public function testAussichtenTrennenDieAbsageStufen(): void
    {
        $aussichten = $this->aussichtenFuerEndstellung(
            [$this->k('KREUZ_NEUN_1'), $this->k('KREUZ_NEUN_2')],
            [
                $this->k('KREUZ_ASS_1'), $this->k('KREUZ_ASS_2'),
                $this->k('KREUZ_ZEHN_1'), $this->k('KREUZ_ZEHN_2'),
                $this->k('KREUZ_KOENIG_1'), $this->k('KREUZ_KOENIG_2'),
            ],
        );

        self::assertSame(0.0, $aussichten->sicherheit(AnsageTyp::SCHWARZ));
        self::assertSame(1.0, $aussichten->sicherheit(AnsageTyp::KEINE_NEUN));
    }

    public function testOhneKandidatenKeineAussichten(): void
    {
        $hand = [$this->k('HERZ_ZEHN_1')];

        self::assertTrue($this->profi->aussichten(
            kandidaten: [],
            eigeneHand: $hand,
            eigenerSitz: 1,
            eigenesTeam: Team::RE,
            offenerStich: [],
            restHandGroessen: [2 => 1, 3 => 1, 4 => 1],
            ungesehene: [$this->k('KREUZ_NEUN_1'), $this->k('PIK_NEUN_1'), $this->k('HERZ_NEUN_1')],
            gedaechtnis: SpielGedaechtnis::ausHistorie([], [], $hand, $this->ordnung),
            variante: SpielVariante::SOLO_DAMEN,
            bekannteTeams: [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::KONTRA, 4 => Team::KONTRA],
            kreuzDameGespieltVon: [],
            ordnung: $this->ordnung,
            zweiteDulle: false,
        )->leer());
    }
}
