<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegelseiteTest extends WebTestCase
{
    public function testRegelseiteIstOeffentlichUndVollstaendig(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/regeln');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Doppelkopf-Regeln');

        // Alle neun Abschnitte vorhanden – das Inhaltsverzeichnis verlinkt genau diese Anker.
        foreach (['ziel', 'blatt', 'trumpf', 'ablauf', 'parteien', 'vorbehalte', 'ansagen', 'abrechnung', 'sonderregeln'] as $anker) {
            self::assertSelectorExists('h2#' . $anker, sprintf('Abschnitt "%s" fehlt.', $anker));
            self::assertSelectorExists('nav a[href="#' . $anker . '"]', sprintf('Verzeichnis-Link auf "%s" fehlt.', $anker));
        }

        $text = $crawler->filter('body')->text();

        // Kein Platzhalter mehr.
        self::assertStringNotContainsString('wird in Phase', $text);

        // Alle sieben Solo-Varianten sind beschrieben.
        foreach (['Karo-Solo', 'Herz-Solo', 'Pik-Solo', 'Kreuz-Solo', 'Damen-Solo', 'Buben-Solo', 'Fleischlos-Solo'] as $solo) {
            self::assertStringContainsString($solo, $text, sprintf('%s fehlt.', $solo));
        }
    }

    public function testGastSiehtRegistrierungsHinweisStattLobbyLink(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/regeln');

        // Die Lobby verlangt ROLE_USER – Gäste dürfen dort nicht hingeschickt werden.
        self::assertCount(0, $crawler->filter('a[href="/lobby"]'));

        // Stattdessen der Hinweis auf die Registrierung (der Link selbst steht auch
        // in der Navigation, deshalb prüfen wir den Text des Schlussabsatzes).
        self::assertStringContainsString('kostenloses Konto', $crawler->filter('body')->text());
    }
}
