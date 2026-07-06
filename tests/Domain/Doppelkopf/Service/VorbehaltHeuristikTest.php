<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\VorbehaltHeuristik;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\BotStaerke;
use App\Enum\SpielVariante;
use App\Enum\VorbehaltTyp;
use PHPUnit\Framework\TestCase;

final class VorbehaltHeuristikTest extends TestCase
{
    private VorbehaltHeuristik $heuristik;

    protected function setUp(): void
    {
        $this->heuristik = new VorbehaltHeuristik();
    }

    /** Beliebige Hand aus 12 Karten (Inhalt egal, wo nur die Anzahl zählt). */
    private function hand12(): array
    {
        $ids = [
            'KREUZ_ASS_1', 'KREUZ_ZEHN_1', 'PIK_ASS_1', 'PIK_ZEHN_1', 'HERZ_ASS_1', 'HERZ_KOENIG_1',
            'KARO_ASS_1', 'KARO_ZEHN_1', 'KARO_KOENIG_1', 'KARO_NEUN_1', 'PIK_KOENIG_1', 'HERZ_NEUN_1',
        ];

        return array_map(fn(string $id) => Karte::vonId($id), $ids);
    }

    public function testZweiKreuzDamenErgibtHochzeit(): void
    {
        $hand = [Karte::vonId('KREUZ_DAME_1'), Karte::vonId('KREUZ_DAME_2'), Karte::vonId('PIK_ASS_1')];

        $e = $this->heuristik->entscheide(BotStaerke::PROFI, $hand, true, 2, ['SOLO_KARO' => 3]);

        self::assertSame(VorbehaltTyp::HOCHZEIT, $e->typ);
    }

    public function testAnfaengerSpieltNieSoloTrotzStarkerHand(): void
    {
        $e = $this->heuristik->entscheide(BotStaerke::ANFAENGER, $this->hand12(), false, 11, ['SOLO_KARO' => 12]);

        self::assertSame(VorbehaltTyp::GESUND, $e->typ);
        self::assertNull($e->soloVariante);
    }

    public function testFortgeschrittenSpieltSoloBeiSehrTrumpfstarkerHand(): void
    {
        // 12 Karten, 10 davon Trumpf im Karo-Solo (nur 2 Nicht-Trümpfe) → Schwelle erreicht.
        $e = $this->heuristik->entscheide(BotStaerke::FORTGESCHRITTEN, $this->hand12(), true, 11, [
            'SOLO_KARO' => 10,
            'SOLO_PIK'  => 6,
        ]);

        self::assertSame(VorbehaltTyp::SOLO, $e->typ);
        self::assertSame(SpielVariante::SOLO_KARO, $e->soloVariante);
    }

    public function testKeinSoloBeiZuSchwacherHandDannArmut(): void
    {
        $e = $this->heuristik->entscheide(BotStaerke::PROFI, $this->hand12(), true, 3, ['SOLO_KARO' => 7]);

        self::assertSame(VorbehaltTyp::ARMUT, $e->typ);
    }

    public function testSonstGesund(): void
    {
        $e = $this->heuristik->entscheide(BotStaerke::FORTGESCHRITTEN, $this->hand12(), false, 6, ['SOLO_KARO' => 6]);

        self::assertSame(VorbehaltTyp::GESUND, $e->typ);
    }
}
