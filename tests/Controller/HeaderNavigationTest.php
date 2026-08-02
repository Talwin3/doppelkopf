<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sichert die zweizeilige Kopfzeile ab. Ob sie optisch passt, lässt sich hier
 * nicht prüfen – wohl aber, dass die Bausteine vorhanden bleiben, die den
 * horizontalen Überlauf auf schmalen Displays verhindern.
 */
final class HeaderNavigationTest extends WebTestCase
{
    public function testHauptlinksErscheinenInlineUndInDerMobilenZweitzeile(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('header_nav');
        $user->setEmail('header_nav@test.invalid');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/lobby');
        self::assertResponseIsSuccessful();

        // Je einmal in der ab sm sichtbaren Leiste und einmal in der Zweitzeile.
        self::assertCount(2, $crawler->filter('nav a[href="/lobby"]'), 'Lobby-Link fehlt in einer der beiden Kopfzeilen.');
        self::assertCount(2, $crawler->filter('nav a[href="/replays"]'));

        self::assertSelectorExists('nav .hidden.sm\\:flex', 'Inline-Leiste (ab sm) fehlt.');
        self::assertSelectorExists('nav .sm\\:hidden', 'Zweitzeile für schmale Displays fehlt.');
    }

    public function testGastSiehtKeineSpielerLinksImHeader(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/regeln');

        self::assertCount(0, $crawler->filter('nav a[href="/lobby"]'));
        self::assertCount(1, $crawler->filter('nav a[href="/registrieren"]'));
    }
}
