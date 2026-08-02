<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PasswortResetAnfrage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswortResetTest extends WebTestCase
{
    private const ALTES_PASSWORT = 'altes-passwort-123';

    public function testVollerAblaufVomLinkBisZumLoginMitNeuemPasswort(): void
    {
        $client = static::createClient();
        $user   = $this->legeUserAn('reset_voll@test.invalid', 'reset_voll');

        $resetUrl = $this->fordereResetAn($client, 'reset_voll@test.invalid');

        // Der Link enthält den Token; die Seite leitet ihn in die Session um und
        // antwortet danach unter der token-freien URL (kein Token im Referer).
        $client->request('GET', $resetUrl);
        self::assertResponseRedirects('/passwort-zuruecksetzen');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $formular = $crawler->selectButton('Passwort speichern')->form();
        $formular['passwort_zuruecksetzen[neuesPasswort][first]']  = 'neues-passwort-456';
        $formular['passwort_zuruecksetzen[neuesPasswort][second]'] = 'neues-passwort-456';
        $client->submit($formular);

        self::assertResponseRedirects('/anmelden');

        // Passwort wurde wirklich getauscht.
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $frisch = $em->getRepository(User::class)->find($user->getId());

        self::assertTrue($hasher->isPasswordValid($frisch, 'neues-passwort-456'));
        self::assertFalse($hasher->isPasswordValid($frisch, self::ALTES_PASSWORT));

        // Und die Anfrage ist verbraucht.
        self::assertCount(0, $em->getRepository(PasswortResetAnfrage::class)->findAll());
    }

    public function testTokenLaesstSichNichtEinZweitesMalVerwenden(): void
    {
        $client = static::createClient();
        $this->legeUserAn('reset_einmal@test.invalid', 'reset_einmal');

        $resetUrl = $this->fordereResetAn($client, 'reset_einmal@test.invalid');

        $client->request('GET', $resetUrl);
        $crawler  = $client->followRedirect();
        $formular = $crawler->selectButton('Passwort speichern')->form();
        $formular['passwort_zuruecksetzen[neuesPasswort][first]']  = 'erstes-neues-123';
        $formular['passwort_zuruecksetzen[neuesPasswort][second]'] = 'erstes-neues-123';
        $client->submit($formular);
        self::assertResponseRedirects('/anmelden');

        // Zweiter Aufruf desselben Links: Token existiert nicht mehr.
        $client->request('GET', $resetUrl);
        $client->followRedirect();
        self::assertResponseRedirects('/passwort-vergessen');
    }

    public function testUnbekannteEmailVerraetNichtsUndVersendetKeineMail(): void
    {
        $client = static::createClient();
        $this->legeUserAn('reset_bekannt@test.invalid', 'reset_bekannt');

        $crawlerBekannt   = $this->sendeAnforderung($client, 'reset_bekannt@test.invalid');
        $antwortBekannt   = $crawlerBekannt->filter('.flash-success')->text();
        self::assertEmailCount(1);

        $crawlerUnbekannt = $this->sendeAnforderung($client, 'gibtesnicht@test.invalid');
        $antwortUnbekannt = $crawlerUnbekannt->filter('.flash-success')->text();

        // Identische Antwort und keine zusätzliche Mail.
        self::assertSame($antwortBekannt, $antwortUnbekannt);
        self::assertEmailCount(0);
    }

    public function testUngueltigerTokenFuehrtZurueckZurAnforderung(): void
    {
        $client = static::createClient();

        $client->request('GET', '/passwort-zuruecksetzen/' . str_repeat('a', 60));
        self::assertResponseRedirects('/passwort-zuruecksetzen');

        $client->followRedirect();
        self::assertResponseRedirects('/passwort-vergessen');
    }

    public function testAufrufOhneTokenLeitetZurAnforderungsSeite(): void
    {
        $client = static::createClient();

        $client->request('GET', '/passwort-zuruecksetzen');
        self::assertResponseRedirects('/passwort-vergessen');
    }

    public function testZweiteAnforderungInnerhalbDerSperreSendetKeineWeitereMail(): void
    {
        $client = static::createClient();
        $this->legeUserAn('reset_throttle@test.invalid', 'reset_throttle');

        $this->sendeAnforderung($client, 'reset_throttle@test.invalid');
        self::assertEmailCount(1);

        $this->sendeAnforderung($client, 'reset_throttle@test.invalid');
        self::assertEmailCount(0);
    }

    private function legeUserAn(string $email, string $username): User
    {
        $c    = static::getContainer();
        $em   = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, self::ALTES_PASSWORT));
        $user->setIsVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Fordert einen Reset an und liest die Reset-URL aus der versendeten Mail. */
    private function fordereResetAn(KernelBrowser $client, string $email): string
    {
        $this->sendeAnforderung($client, $email);

        self::assertEmailCount(1);
        $nachricht = self::getMailerMessage();
        self::assertNotNull($nachricht);

        $html = $nachricht->getHtmlBody();
        self::assertIsString($html);
        self::assertSame(1, preg_match('#href="([^"]*/passwort-zuruecksetzen/[^"]+)"#', $html, $treffer));

        return $treffer[1];
    }

    private function sendeAnforderung(KernelBrowser $client, string $email): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler  = $client->request('GET', '/passwort-vergessen');
        $formular = $crawler->selectButton('Zurücksetz-Link anfordern')->form(['email' => $email]);

        $client->submit($formular);

        return $client->getCrawler();
    }
}
