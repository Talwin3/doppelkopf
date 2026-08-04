<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

final class AnsageHeuristikTest extends TestCase
{
    private AnsageHeuristik $heuristik;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->heuristik = new AnsageHeuristik();
        $this->ordnung   = new NormalspielTrumpfOrdnung();
    }

    private function karten(array $ids): array
    {
        return array_map(fn(string $id) => Karte::vonId($id), $ids);
    }

    /** 8 Trümpfe inkl. Kreuz-Dame → starke Hand. */
    private function starkeHand(): array
    {
        return $this->karten([
            'KREUZ_DAME_1', 'PIK_DAME_1', 'HERZ_DAME_1', 'KARO_DAME_1',
            'KREUZ_BUBE_1', 'PIK_BUBE_1', 'KARO_ASS_1', 'KARO_ZEHN_1',
            'KREUZ_ASS_1', 'PIK_ASS_1',
        ]);
    }

    /** Genau 7 Trümpfe inkl. Kreuz-Dame – reicht für Re, nicht für Contra. */
    private function mittlereHand(): array
    {
        return $this->karten([
            'KREUZ_DAME_1', 'PIK_DAME_1', 'HERZ_DAME_1', 'KARO_DAME_1',
            'KREUZ_BUBE_1', 'PIK_BUBE_1', 'KARO_ASS_1',
            'KREUZ_ASS_1', 'PIK_ASS_1',
        ]);
    }

    private function schwacheHand(): array
    {
        return $this->karten(['KREUZ_ASS_1', 'PIK_ASS_1', 'HERZ_ASS_1', 'KREUZ_KOENIG_1', 'PIK_KOENIG_1']);
    }

    public function testAnfaengerSagtNieAn(): void
    {
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::ANFAENGER, $this->starkeHand(), Team::RE, $this->ordnung));
    }

    public function testOhneTeamKeineAnsage(): void
    {
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::PROFI, $this->starkeHand(), null, $this->ordnung));
    }

    public function testStarkeHandReSagtRe(): void
    {
        self::assertSame(AnsageTyp::RE, $this->heuristik->willAnsagen(BotStaerke::FORTGESCHRITTEN, $this->starkeHand(), Team::RE, $this->ordnung));
    }

    public function testStarkeHandKontraSagtContra(): void
    {
        self::assertSame(AnsageTyp::CONTRA, $this->heuristik->willAnsagen(BotStaerke::PROFI, $this->starkeHand(), Team::KONTRA, $this->ordnung));
    }

    public function testSchwacheHandKeineAnsage(): void
    {
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::PROFI, $this->schwacheHand(), Team::RE, $this->ordnung));
    }

    /**
     * Kern der Essener Asymmetrie: dasselbe Blatt reicht für Re, aber nicht für Contra.
     * KONTRA steht strukturell schwächer und braucht deshalb mehr.
     */
    public function testContraBrauchtMehrAlsRe(): void
    {
        $hand = $this->mittlereHand();

        self::assertSame(AnsageTyp::RE, $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::RE, $this->ordnung));
        self::assertNull($this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::KONTRA, $this->ordnung));
    }

    /**
     * Mit Fristfunktion sagt der Bot erst an, wenn seine Hand auf die Frist abgeschmolzen
     * ist (Re/Contra: 11 Karten) – nicht schon mit voller Hand.
     */
    public function testWartetBisZumLetztmoeglichenZeitpunkt(): void
    {
        $frist = static fn(AnsageTyp $typ): int => $typ === AnsageTyp::RE || $typ === AnsageTyp::CONTRA ? 11 : 10;
        $voll  = $this->starkeHand();

        // Frist künstlich über die Handgröße legen → noch zu früh.
        $zuFrueh = static fn(AnsageTyp $typ): int => 5;
        self::assertNull(
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $voll, Team::RE, $this->ordnung, null, [], $zuFrueh),
            'Mit voller Hand ist der letztmögliche Zeitpunkt noch nicht erreicht.',
        );

        // Frist erreicht → jetzt wird angesagt.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $voll, Team::RE, $this->ordnung, null, [], $frist),
        );

        // Ohne Fristfunktion bleibt es beim alten Sofort-Ansagen.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $voll, Team::RE, $this->ordnung, null, [], null),
        );

        // Abgeschaltet über die Schwellen → ebenfalls sofort.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $voll, Team::RE, $this->ordnung,
                new AnsageSchwellen(7, 8, [], false), [], $zuFrueh,
            ),
        );
    }

    /**
     * Beim Warten darf die geschrumpfte Hand die Bewertung nicht verfälschen: gespielte
     * Trümpfe zählen weiter mit, sonst fällt ein Teil der Ansagen aus einem sachfremden
     * Grund weg (im Benchmark 6 % der Ansagen).
     */
    public function testBewertungsHandSchlaegtAktuelleHand(): void
    {
        $stark   = $this->starkeHand();
        $schwach = $this->schwacheHand();

        // Aktuelle Hand zu schwach, ausgeteilte Hand stark → Ansage.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $schwach, Team::RE, $this->ordnung, null, [], null, $stark,
            ),
        );
    }

    public function testOhneKonfigurierteSchwelleKeineAbsage(): void
    {
        // Selbst mit Traumblatt und stehendem "Re": Absagen sind per Default abgeschaltet.
        self::assertNull($this->heuristik->willAnsagen(
            BotStaerke::PROFI,
            $this->starkeHand(),
            Team::RE,
            $this->ordnung,
            null,
            [AnsageTyp::RE],
        ));
    }

    public function testAbsagenFolgenDerKette(): void
    {
        $schwellen = new AnsageSchwellen(7, 8, [
            AnsageTyp::KEINE_NEUN->value  => 8,
            AnsageTyp::KEINE_SECHS->value => 8,
        ]);
        $hand = $this->starkeHand(); // 8 Trümpfe

        // Ohne "Re" kommt zuerst die Grundansage.
        self::assertSame(
            AnsageTyp::RE,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, []),
        );

        // Mit "Re" die erste offene Absagestufe …
        self::assertSame(
            AnsageTyp::KEINE_NEUN,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen, [AnsageTyp::RE]),
        );

        // … dann die nächste …
        self::assertSame(
            AnsageTyp::KEINE_SECHS,
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE, AnsageTyp::KEINE_NEUN],
            ),
        );

        // … und Schluss, weil "keine 30" keine Schwelle hat.
        self::assertNull(
            $this->heuristik->willAnsagen(
                BotStaerke::PROFI, $hand, Team::RE, $this->ordnung, $schwellen,
                [AnsageTyp::RE, AnsageTyp::KEINE_NEUN, AnsageTyp::KEINE_SECHS],
            ),
        );
    }

    public function testGleicheSchwellenStellenDasAlteVerhaltenHer(): void
    {
        $hand = $this->mittlereHand();

        self::assertSame(
            AnsageTyp::CONTRA,
            $this->heuristik->willAnsagen(BotStaerke::PROFI, $hand, Team::KONTRA, $this->ordnung, AnsageSchwellen::gleich(7)),
        );
    }
}
