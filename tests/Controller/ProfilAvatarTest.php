<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfilAvatarTest extends WebTestCase
{
    public function testWuerfelnRouteExistiertNichtMehr(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $user   = $this->neuerUser($em, 'avatar_route');
        $client->loginUser($user);

        $client->request('POST', '/einstellungen/avatar/wuerfeln');
        self::assertResponseStatusCodeSame(404, 'Würfeln darf nichts mehr serverseitig speichern');
    }

    public function testSpeichernUebernimmtStilUndSeed(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $user   = $this->neuerUser($em, 'avatar_save');
        $userId = $user->getId();

        $alterSeed = $user->getAvatarSeed();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/einstellungen');
        self::assertResponseIsSuccessful();
        // Vorschau wird vom Stimulus-Controller gesteuert.
        self::assertSelectorExists('[data-controller="avatar-auswahl"]');
        self::assertSelectorExists('input[name="avatar_seed"]');

        $form = $crawler->filter('form[action="/einstellungen/avatar"]')->form();
        $form['avatar_seed']->setValue('deadbeefcafe1234');
        $form['avatar_stil']->select('bottts');
        $client->submit($form);

        self::assertResponseRedirects('/einstellungen');

        $em->clear();
        $frisch = $em->getRepository(User::class)->find($userId);
        self::assertSame('bottts', $frisch->getAvatarStil()->value);
        self::assertSame('deadbeefcafe1234', $frisch->getAvatarSeed());
        self::assertNotSame($alterSeed, $frisch->getAvatarSeed());
    }

    private function neuerUser(EntityManagerInterface $em, string $name): User
    {
        $user = new User();
        $user->setUsername($name);
        $user->setEmail($name . '@test.invalid');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
