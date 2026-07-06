<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\BotStaerke;
use PHPUnit\Framework\TestCase;

final class BotStaerkeTest extends TestCase
{
    public function testDefaultIstAnfaenger(): void
    {
        self::assertSame(BotStaerke::ANFAENGER, BotStaerke::default());
    }

    public function testVonWertErkenntGueltigeWerte(): void
    {
        self::assertSame(BotStaerke::PROFI, BotStaerke::vonWert('profi'));
        self::assertSame(BotStaerke::FORTGESCHRITTEN, BotStaerke::vonWert('fortgeschritten'));
    }

    public function testVonWertFaelltBeiUnbekanntAufDefaultZurueck(): void
    {
        self::assertSame(BotStaerke::ANFAENGER, BotStaerke::vonWert('gibtsnicht'));
        self::assertSame(BotStaerke::ANFAENGER, BotStaerke::vonWert(null));
    }

    public function testJedeStufeHatLabelUndBeschreibung(): void
    {
        foreach (BotStaerke::cases() as $staerke) {
            self::assertNotSame('', $staerke->label());
            self::assertNotSame('', $staerke->beschreibung());
        }
    }
}
