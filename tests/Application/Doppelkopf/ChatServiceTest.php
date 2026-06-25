<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\ChatService;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

final class ChatServiceTest extends DoppelkopfIntegrationTestCase
{
    private function chat(): ChatService
    {
        return static::getContainer()->get(ChatService::class);
    }

    public function testAktiverSpielerKannSendenUndVerlaufEnthaeltNachricht(): void
    {
        $host  = $this->neuerUser('chat_host');
        $tisch = $this->beitritt->erstelleTisch($host, 'Chat-Tisch', ZugangsModusTyp::OFFEN);

        $nachricht = $this->chat()->sendeNachricht($tisch, $host, '  Hallo zusammen  ');

        self::assertSame('Hallo zusammen', $nachricht->getText(), 'Text wird getrimmt');
        self::assertSame('chat_host', $nachricht->getAbsenderName());
        self::assertSame($host->getId(), $nachricht->getAbsender()?->getId());

        $verlauf = $this->chat()->verlauf($tisch);
        self::assertCount(1, $verlauf);
        self::assertSame('Hallo zusammen', $verlauf[0]->getText());
    }

    public function testNichtMitgliedKannNichtChatten(): void
    {
        $host    = $this->neuerUser('chat_owner');
        $fremder = $this->neuerUser('chat_fremder');
        $tisch   = $this->beitritt->erstelleTisch($host, 'Chat-Tisch-2', ZugangsModusTyp::OFFEN);

        $this->expectException(\DomainException::class);
        $this->chat()->sendeNachricht($tisch, $fremder, 'darf ich nicht');
    }

    public function testLeereNachrichtWirdAbgelehnt(): void
    {
        $host  = $this->neuerUser('chat_leer');
        $tisch = $this->beitritt->erstelleTisch($host, 'Chat-Tisch-3', ZugangsModusTyp::OFFEN);

        $this->expectException(\DomainException::class);
        $this->chat()->sendeNachricht($tisch, $host, '   ');
    }

    public function testZuLangeNachrichtWirdGekuerzt(): void
    {
        $host  = $this->neuerUser('chat_lang');
        $tisch = $this->beitritt->erstelleTisch($host, 'Chat-Tisch-4', ZugangsModusTyp::OFFEN);

        $lang = str_repeat('a', ChatService::MAX_LAENGE + 50);
        $nachricht = $this->chat()->sendeNachricht($tisch, $host, $lang);

        self::assertSame(ChatService::MAX_LAENGE, mb_strlen($nachricht->getText()));
    }
}
