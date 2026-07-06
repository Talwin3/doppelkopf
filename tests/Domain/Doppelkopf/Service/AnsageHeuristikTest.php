<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\AnsageHeuristik;
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
}
