<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\SystemEinstellungService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SystemEinstellungPruefungTest extends KernelTestCase
{
    private SystemEinstellungService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(SystemEinstellungService::class);
    }

    public function testGanzzahligeEinstellungAkzeptiertZahlImBereich(): void
    {
        self::assertNull($this->service->pruefeWert('bestenliste_min_spiele_monat', '50'));
        self::assertNull($this->service->pruefeWert('bestenliste_min_spiele_monat', '0'));
    }

    public function testGanzzahligeEinstellungLehntTextUndUeberlaufAb(): void
    {
        self::assertNotNull($this->service->pruefeWert('bestenliste_min_spiele_jahr', 'viele'));
        self::assertNotNull($this->service->pruefeWert('bestenliste_min_spiele_jahr', '-3'));
        self::assertNotNull($this->service->pruefeWert('bestenliste_min_spiele_jahr', '99999999999999999999'));
    }

    public function testDisconnectTimeoutHatUntergrenze(): void
    {
        self::assertNotNull($this->service->pruefeWert('disconnect_timeout_sekunden', '0'));
        self::assertNull($this->service->pruefeWert('disconnect_timeout_sekunden', '30'));
    }

    public function testTextEinstellungWirdNichtGeprueft(): void
    {
        self::assertNull($this->service->pruefeWert('bot_avatar_stil', 'bottts'));
    }
}
