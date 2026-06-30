<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Emoji\EmojiKatalog;
use App\Twig\Extension\EmojiExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmojiExtensionTest extends KernelTestCase
{
    private function extension(): EmojiExtension
    {
        self::bootKernel();

        return static::getContainer()->get(EmojiExtension::class);
    }

    public function testErsetztBekanntesEmojiDurchBild(): void
    {
        $html = $this->extension()->ersetze("Gut gespielt \u{1F44D}");

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('class="chat-emoji"', $html);
        self::assertStringContainsString('emoji/1f44d.svg', $html);
        self::assertStringNotContainsString("\u{1F44D}", $html);
    }

    public function testErsetztKartenfarbeMitVariationsSelektor(): void
    {
        $html = $this->extension()->ersetze("Trumpf \u{2663}\u{FE0F}");

        self::assertStringContainsString('emoji/2663.svg', $html);
    }

    public function testEscaptHtmlImText(): void
    {
        $html = $this->extension()->ersetze('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTextOhneEmojiBleibtUnveraendert(): void
    {
        self::assertSame('Hallo Welt', $this->extension()->ersetze('Hallo Welt'));
    }

    public function testKatalogUndMapVollstaendig(): void
    {
        $ext = $this->extension();

        self::assertCount(count(EmojiKatalog::LISTE), $ext->katalog());

        $map = json_decode($ext->mapJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey("\u{1F44D}", $map);
        self::assertStringContainsString('emoji/1f44d.svg', $map["\u{1F44D}"]);
    }
}
