<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\RolloutPolitik;
use App\Domain\Doppelkopf\Service\TeamEvidenz;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\SpielVariante;
use PHPUnit\Framework\TestCase;

/**
 * Der Evidenz-Detektor ist Messwerkzeug: die Diagnose, die über einen mehrstündigen Messlauf
 * entscheidet, kann nur so gut sein wie er. Ein Signal, das die falschen Sitze paart oder
 * Stechen mit Schmieren verwechselt, würde eine Trefferquote nahe 50 % ausweisen und den
 * Hebel fälschlich beerdigen.
 */
final class TeamEvidenzTest extends TestCase
{
    private TeamEvidenz $evidenz;
    private TrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->evidenz = new TeamEvidenz(new RolloutPolitik());
        $this->ordnung = (new TrumpfOrdnungFactory())->fuer(SpielVariante::NORMALSPIEL);
    }

    /** @param list<array{0: int, 1: string}> $karten Sitzplatz und Karten-ID in Spielreihenfolge */
    private function stich(array $karten): array
    {
        return array_map(
            static fn(array $k) => ['sitzplatz' => $k[0], 'karte' => Karte::vonId($k[1])],
            $karten,
        );
    }

    public function testHoheAugenAufFremdGefuehrtenStichSindEinPartnerSignal(): void
    {
        // Sitz 1 spielt Pik-König an, Sitz 2 legt das Pik-Ass drauf und übernimmt damit –
        // das ist Stechen. Sitz 3 legt die Pik-Zehn nach: er kann Sitz 2 nicht mehr
        // schlagen und gibt ihm trotzdem 10 Augen. Klassisches Schmieren.
        $aussagen = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_KOENIG_1'], [2, 'PIK_ASS_1'], [3, 'PIK_ZEHN_1'], [4, 'PIK_NEUN_1']]),
            0,
            $this->ordnung,
        );

        self::assertCount(2, $aussagen, 'Erwartet: ein Stech- und ein Schmier-Signal.');

        self::assertSame(TeamEvidenz::SIGNAL_STECHEN, $aussagen[0]['signal']);
        self::assertSame([2, 1], [$aussagen[0]['a'], $aussagen[0]['b']]);
        self::assertFalse($aussagen[0]['gleichesTeam'], 'Wer den Führenden überbietet, spielt gegen ihn.');

        self::assertSame(TeamEvidenz::SIGNAL_SCHMIEREN_BEDIENT_FRUEH, $aussagen[1]['signal']);
        self::assertSame([3, 2], [$aussagen[1]['a'], $aussagen[1]['b']]);
        self::assertTrue($aussagen[1]['gleichesTeam'], 'Wer schmiert, hält den Führenden für seinen Partner.');
    }

    public function testSchmierenOhneZuBedienenIstDasStaerkereSignal(): void
    {
        // Sitz 3 hat kein Pik mehr und wirft trotzdem das Herz-Ass ab, statt eine billige
        // Karte loszuwerden – hier stand die ganze Hand zur Wahl, der Hinweis wiegt schwerer.
        $aussagen = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_ASS_1'], [2, 'PIK_NEUN_1'], [3, 'HERZ_ASS_1'], [4, 'PIK_KOENIG_1']]),
            0,
            $this->ordnung,
        );

        self::assertCount(1, $aussagen);
        self::assertSame(TeamEvidenz::SIGNAL_SCHMIEREN_ABWURF_FRUEH, $aussagen[0]['signal']);
        self::assertSame([3, 1], [$aussagen[0]['a'], $aussagen[0]['b']]);
        self::assertTrue($aussagen[0]['gleichesTeam']);
    }

    /**
     * Die Position trennt die Signale: wer als Letzter hohe Augen abgibt, weiß, dass der Stich
     * dem Führenden sicher gehört – die Regel-Bots schmieren ausschließlich so
     * ({@see \App\Domain\Doppelkopf\Service\BotHeuristik}), und bei Menschen ist es der
     * häufigste Fall. Wer früher schmiert, riskiert mehr und tut es deshalb überlegter.
     */
    public function testDieLetztePositionBekommtEinEigenesSignal(): void
    {
        $letzter = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_ASS_1'], [2, 'PIK_NEUN_1'], [3, 'PIK_KOENIG_1'], [4, 'HERZ_ASS_1']]),
            0,
            $this->ordnung,
        );
        self::assertCount(1, $letzter);
        self::assertSame(TeamEvidenz::SIGNAL_SCHMIEREN_ABWURF_LETZTER, $letzter[0]['signal']);

        $bedientLetzter = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_ASS_1'], [2, 'PIK_NEUN_1'], [3, 'PIK_KOENIG_1'], [4, 'PIK_ZEHN_1']]),
            0,
            $this->ordnung,
        );
        self::assertCount(1, $bedientLetzter);
        self::assertSame(TeamEvidenz::SIGNAL_SCHMIEREN_BEDIENT_LETZTER, $bedientLetzter[0]['signal']);
    }

    public function testDerStichfuehrerWandertMit(): void
    {
        // Sitz 1 spielt an, Sitz 2 sticht mit Trumpf, Sitz 3 übertrumpft. Die zweite Aussage
        // muss sich auf Sitz 2 beziehen – nicht mehr auf den ursprünglichen Anspieler.
        $aussagen = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_ASS_1'], [2, 'KARO_BUBE_1'], [3, 'KREUZ_BUBE_1'], [4, 'PIK_NEUN_1']]),
            0,
            $this->ordnung,
        );

        self::assertCount(2, $aussagen);
        self::assertSame([2, 1], [$aussagen[0]['a'], $aussagen[0]['b']]);
        self::assertSame([3, 2], [$aussagen[1]['a'], $aussagen[1]['b']], 'Der Bezug ist der jeweils aktuelle Führende.');
    }

    public function testNiedrigeAugenAufFremdenStichSagenNichts(): void
    {
        // Wer bedient und dabei nichts Wertvolles abgibt, verrät nichts über seine Partei.
        // (Damen und Buben scheiden als Füllkarten aus – sie sind im Normalspiel Trumpf.)
        $aussagen = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_ASS_1'], [2, 'PIK_NEUN_1'], [3, 'PIK_KOENIG_1'], [4, 'PIK_NEUN_2']]),
            0,
            $this->ordnung,
        );

        self::assertSame([], $aussagen);
    }

    public function testEineTrumpfZehnAufEineFehlfarbeIstStechenUndKeinSchmieren(): void
    {
        // Die Karo-Zehn zählt 10 Augen, ist als Karo im Normalspiel aber Trumpf. Sie darf
        // nicht als Schmieren durchgehen – sie nimmt den Stich weg.
        $aussagen = $this->evidenz->fuerStich(
            $this->stich([[1, 'PIK_KOENIG_1'], [2, 'KARO_ZEHN_1'], [3, 'PIK_NEUN_1'], [4, 'PIK_NEUN_2']]),
            0,
            $this->ordnung,
        );

        self::assertCount(1, $aussagen);
        self::assertSame(TeamEvidenz::SIGNAL_STECHEN, $aussagen[0]['signal']);
        self::assertFalse($aussagen[0]['gleichesTeam']);
    }

    public function testUnvollstaendigeUndLeereSticheLiefernNichts(): void
    {
        self::assertSame([], $this->evidenz->fuerStich([], 0, $this->ordnung));
        self::assertSame([], $this->evidenz->fuerStich($this->stich([[1, 'PIK_ASS_1']]), 0, $this->ordnung));
    }

    public function testAuswertenReichtDieStichnummerDurch(): void
    {
        $stich    = $this->stich([[1, 'PIK_KOENIG_1'], [2, 'PIK_ASS_1'], [3, 'PIK_NEUN_1'], [4, 'PIK_NEUN_2']]);
        $aussagen = $this->evidenz->auswerten([$stich, $stich], $this->ordnung);

        self::assertCount(2, $aussagen);
        self::assertSame(0, $aussagen[0]['stichNr']);
        self::assertSame(1, $aussagen[1]['stichNr'], 'Der Zeitpunkt entscheidet über den Wert der Evidenz.');
    }
}
