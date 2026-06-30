<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Emoji\EmojiKatalog;
use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Rendert die kuratierten Chat-Emojis als self-hosted SVG-Bilder und stellt den
 * Katalog für den Picker bereit. Native Unicode bleibt der Transport (im
 * Nachrichtentext), die Bilder sorgen nur für einheitliche Darstellung.
 */
final class EmojiExtension extends AbstractExtension
{
    public function __construct(
        private readonly Packages $packages,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('emoji', $this->ersetze(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('emoji_katalog', $this->katalog(...)),
            new TwigFunction('emoji_map_json', $this->mapJson(...)),
        ];
    }

    /**
     * Ersetzt bekannte Emoji-Sequenzen in einem Text durch <img>-Tags.
     * Der Text wird zuerst escaped; die Unicode-Zeichen überstehen das
     * unverändert, sodass die Ersetzung danach sicher greift (kein XSS).
     */
    public function ersetze(?string $text): string
    {
        $html = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach (EmojiKatalog::LISTE as $unicode => [$slug, $name]) {
            if (!str_contains($html, $unicode)) {
                continue;
            }
            $html = str_replace($unicode, $this->imgTag($slug, $name), $html);
        }

        return $html;
    }

    /**
     * Geordneter Katalog für den Picker.
     *
     * @return list<array{unicode: string, url: string, name: string}>
     */
    public function katalog(): array
    {
        $eintraege = [];
        foreach (EmojiKatalog::LISTE as $unicode => [$slug, $name]) {
            $eintraege[] = ['unicode' => $unicode, 'url' => $this->url($slug), 'name' => $name];
        }

        return $eintraege;
    }

    /** Unicode→URL als JSON-String für das Live-Rendering im JS. */
    public function mapJson(): string
    {
        $map = [];
        foreach (EmojiKatalog::LISTE as $unicode => [$slug]) {
            $map[$unicode] = $this->url($slug);
        }

        return json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function imgTag(string $slug, string $name): string
    {
        $alt = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<img class="chat-emoji" src="%s" alt="%s" draggable="false">',
            htmlspecialchars($this->url($slug), ENT_QUOTES, 'UTF-8'),
            $alt,
        );
    }

    private function url(string $slug): string
    {
        return $this->packages->getUrl('emoji/' . $slug . '.svg');
    }
}
