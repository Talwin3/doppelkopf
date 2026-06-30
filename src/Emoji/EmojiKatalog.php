<?php

declare(strict_types=1);

namespace App\Emoji;

/**
 * Kuratierte Emoji-Auswahl für den Tisch-Chat. Bewusst klein gehalten und
 * passend zum Kartenspiel. Die Bilder liegen self-hosted als OpenMoji-SVGs
 * unter public/emoji/<slug>.svg (CC BY-SA 4.0, Hinweis im Impressum).
 */
final class EmojiKatalog
{
    /**
     * Geordnete Liste in Anzeigereihenfolge.
     * Schlüssel = exakte Unicode-Sequenz, wie sie im Nachrichtentext steht
     * (Kartenfarben mit Variations-Selektor U+FE0F für Emoji-Darstellung).
     * Wert = [Slug (Dateiname ohne .svg), Kurzname].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const LISTE = [
        "\u{1F44D}"          => ['1f44d', 'Daumen hoch'],
        "\u{1F602}"          => ['1f602', 'Tränen lachen'],
        "\u{1F605}"          => ['1f605', 'Schwitzendes Lächeln'],
        "\u{1F609}"          => ['1f609', 'Zwinkern'],
        "\u{1F60E}"          => ['1f60e', 'Sonnenbrille'],
        "\u{1F914}"          => ['1f914', 'Nachdenklich'],
        "\u{1F622}"          => ['1f622', 'Weinen'],
        "\u{1F62E}"          => ['1f62e', 'Überrascht'],
        "\u{1F44F}"          => ['1f44f', 'Applaus'],
        "\u{1F389}"          => ['1f389', 'Tata'],
        "\u{1F525}"          => ['1f525', 'Feuer'],
        "\u{2663}\u{FE0F}"   => ['2663', 'Kreuz'],
        "\u{2665}\u{FE0F}"   => ['2665', 'Herz'],
        "\u{2666}\u{FE0F}"   => ['2666', 'Karo'],
        "\u{2660}\u{FE0F}"   => ['2660', 'Pik'],
        "\u{1F0CF}"          => ['1f0cf', 'Joker'],
        "\u{1F91D}"          => ['1f91d', 'Handschlag'],
        "\u{1F634}"          => ['1f634', 'Schlafen'],
        "\u{1F340}"          => ['1f340', 'Kleeblatt'],
        "\u{23F3}"           => ['23f3', 'Sanduhr'],
        "\u{1F44B}"          => ['1f44b', 'Winken'],
        "\u{1F648}"          => ['1f648', 'Nichts sehen'],
        "\u{1F4AA}"          => ['1f4aa', 'Muskel'],
        "\u{1F91E}"          => ['1f91e', 'Daumen drücken'],
    ];
}
