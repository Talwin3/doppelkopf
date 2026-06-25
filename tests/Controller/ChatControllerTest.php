<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\ZugangsModusTyp;
use App\Repository\ChatNachrichtRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChatControllerTest extends WebTestCase
{
    public function testChatPanelWirdGerendertUndNachrichtGesendet(): void
    {
        $client = static::createClient();
        $c      = static::getContainer();
        $em     = $c->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('chat_ctrl');
        $user->setEmail('chat_ctrl@test.invalid');
        $em->persist($user);
        $em->flush();

        /** @var Tisch $tisch */
        $tisch = $c->get(TischBeitrittsService::class)
            ->erstelleTisch($user, 'Chat-Ctrl', ZugangsModusTyp::OFFEN);
        $tischId = $tisch->getId();

        // EM leeren, damit der Controller den Tisch frisch (mit synchroner
        // Spieler-Collection) lädt statt der stale Instanz aus dem Setup.
        $em->clear();

        $client->loginUser($user);

        $crawler = $client->request('GET', '/spieltisch/' . $tischId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="chat"]');

        // Token aus der gerenderten Seite (gleiche Session → gültig).
        $token = $crawler->filter('[data-chat-csrf-value]')->attr('data-chat-csrf-value');

        $client->request('POST', '/spieltisch/' . $tischId . '/chat', [
            '_token' => $token,
            'text'   => 'Hallo Tisch!',
        ]);
        self::assertResponseIsSuccessful();
        self::assertJson($client->getResponse()->getContent());

        $em->clear();
        $tischFrisch = $em->getRepository(Tisch::class)->find($tischId);
        $verlauf = $c->get(ChatNachrichtRepository::class)->findeLetzteFuerTisch($tischFrisch);
        self::assertCount(1, $verlauf);
        self::assertSame('Hallo Tisch!', $verlauf[0]->getText());
    }

    public function testUngueltigesCsrfTokenWirdAbgelehnt(): void
    {
        $client = static::createClient();
        $c      = static::getContainer();
        $em     = $c->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('chat_csrf');
        $user->setEmail('chat_csrf@test.invalid');
        $em->persist($user);
        $em->flush();

        $tisch = $c->get(TischBeitrittsService::class)
            ->erstelleTisch($user, 'Chat-CSRF', ZugangsModusTyp::OFFEN);

        $client->loginUser($user);
        $client->request('POST', '/spieltisch/' . $tisch->getId() . '/chat', [
            '_token' => 'falsch',
            'text'   => 'sollte scheitern',
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
