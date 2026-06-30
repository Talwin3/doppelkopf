<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\ChatPhrasenService;
use App\Application\SystemEinstellungService;
use App\Entity\User;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

final class ChatPhrasenServiceTest extends DoppelkopfIntegrationTestCase
{
    private function service(): ChatPhrasenService
    {
        return static::getContainer()->get(ChatPhrasenService::class);
    }

    public function testParseListeTrimmtUndFiltertLeerzeilen(): void
    {
        $phrasen = $this->service()->parseListe("  Hallo  \n\n  Tschüss\n   \n");

        self::assertSame(['Hallo', 'Tschüss'], $phrasen);
    }

    public function testParseListeDedupliziertUndBegrenztAnzahl(): void
    {
        $roh = "A\nA\nB\nC\nD\nE\nF\nG\nH\nI\nJ\nK\nL\nM\nN";
        $phrasen = $this->service()->parseListe($roh);

        self::assertSame(ChatPhrasenService::MAX_PERSOENLICH, count($phrasen));
        self::assertSame(array_values(array_unique($phrasen)), $phrasen);
    }

    public function testParseListeKuerztZuLangePhrasen(): void
    {
        $lang = str_repeat('x', ChatPhrasenService::MAX_LAENGE + 50);
        $phrasen = $this->service()->parseListe($lang);

        self::assertCount(1, $phrasen);
        self::assertSame(ChatPhrasenService::MAX_LAENGE, mb_strlen($phrasen[0]));
    }

    public function testFuerUserMergtAdminDefaultsMitEigenenUndDedupliziert(): void
    {
        static::getContainer()->get(SystemEinstellungService::class)
            ->set(ChatPhrasenService::ADMIN_SCHLUESSEL, "Gut gespielt!\nGlückwunsch!");

        $user = new User();
        $user->setChatPhrasen(['Glückwunsch!', 'Na sowas …']);

        $phrasen = $this->service()->fuerUser($user);

        self::assertSame(['Gut gespielt!', 'Glückwunsch!', 'Na sowas …'], $phrasen);
    }
}
