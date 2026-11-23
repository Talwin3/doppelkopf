<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\TischPunkteAnzeige;
use App\Enum\TischSteuerungsModus;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use App\Repository\TischRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gesamtkonto Stufe 2 im Browser-Durchlauf: DDV-Knopf in der Lobby, Kategorie-Abzeichen,
 * Punkteleiste am Tisch und der Umschalter in den Tisch-Einstellungen.
 */
final class GesamtkontoTischSeiteTest extends WebTestCase
{
    public function testDdvKnopfEroeffnetGesperrtenDdvTisch(): void
    {
        [$client, $user] = $this->angemeldet('gs_ddv');

        $crawler = $client->request('GET', '/lobby');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('DDV-Tisch eröffnen')->form([
            'tisch_erstellen[name]' => 'Turnierrunde',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/lobby');

        $tisch = static::getContainer()->get(TischRepository::class)->findOneBy(['name' => 'Turnierrunde']);
        self::assertInstanceOf(Tisch::class, $tisch);
        self::assertTrue($tisch->isDdvTisch());
        self::assertSame(WertungsKategorie::DDV, $tisch->getWertungsKategorie());

        $crawler = $client->followRedirect();
        self::assertSelectorExists('[title*="DDV-Tisch, Regeln gesperrt"]');

        // Tischseite: Hinweis und gesperrte Regel-Checkboxen
        $crawler = $client->request('GET', '/spieltisch/' . $tisch->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Es gelten fest die DDV-Turnierspielregeln');
        self::assertNotNull($crawler->filter('input[name="schweinchen"]')->attr('disabled'));
        self::assertNull($crawler->filter('input[name="augen_mouseover"]')->attr('disabled'));

        // Speichern ohne die gesperrten Felder: Antwort nennt die tatsächlich geltenden Regeln
        $token = $crawler->filter('form[action$="/regelwerk-speichern"] input[name="_token"]')->attr('value');
        $client->request('POST', '/spieltisch/' . $tisch->getId() . '/regelwerk-speichern', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $antwort = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(7, $antwort['regelwerk']['soli_erlaubt']);
        self::assertFalse($antwort['regelwerk']['augen_mouseover']);
    }

    public function testNormalerKnopfEroeffnetUngesperrtenTisch(): void
    {
        [$client] = $this->angemeldet('gs_normal');

        $crawler = $client->request('GET', '/lobby');
        $client->submit($crawler->selectButton('Tisch erstellen')->form([
            'tisch_erstellen[name]' => 'Freie Runde',
        ]));

        $tisch = static::getContainer()->get(TischRepository::class)->findOneBy(['name' => 'Freie Runde']);
        self::assertInstanceOf(Tisch::class, $tisch);
        self::assertFalse($tisch->isDdvTisch());
    }

    public function testTischseiteZeigtPunkteleisteUndSchaltetUm(): void
    {
        [$client, $user] = $this->angemeldet('gs_leiste');
        $tisch = static::getContainer()->get(TischBeitrittsService::class)
            ->erstelleTisch($user, 'Leisten-Tisch', ZugangsModusTyp::OFFEN, TischSteuerungsModus::NUR_ERSTELLER);
        $id = $tisch->getId();
        // Frisch laden lassen: die inverse Spieler-Collection ist sonst noch leer.
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $crawler = $client->request('GET', '/spieltisch/' . $id);
        self::assertResponseIsSuccessful();
        $leiste = $crawler->filter('[data-spieltisch-target="punkte"]');
        self::assertCount(1, $leiste);
        self::assertStringContainsString('Monatspunkte', $leiste->text());
        self::assertStringContainsString('gs_leiste', $leiste->text());
        self::assertMatchesRegularExpression('/0 von \d+ Spielen/', $leiste->text());

        // Umschalten über die Tisch-Einstellungen
        $token = $crawler->filter('form[action$="/punkte-anzeige"] input[name="_token"]')->attr('value');
        $client->request('POST', '/spieltisch/' . $id . '/punkte-anzeige', ['_token' => $token, 'anzeige' => 'TISCH']);
        self::assertResponseIsSuccessful();

        $neu = static::getContainer()->get(TischRepository::class)->find($id);
        self::assertInstanceOf(Tisch::class, $neu);
        static::getContainer()->get(EntityManagerInterface::class)->refresh($neu);
        self::assertSame(TischPunkteAnzeige::TISCH, $neu->getPunkteAnzeige());

        // Der Zustands-Reload liefert die Leiste nur auf Anforderung mit
        $client->request('GET', '/spieltisch/' . $id . '/zustand?punkte=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-bereich="punkte"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Tischliste', (string) $client->getResponse()->getContent());
        // Einstellungs-Panel kommt mit und zeigt den neuen Stand
        self::assertStringContainsString('data-bereich="einstellungen"', (string) $client->getResponse()->getContent());
        self::assertMatchesRegularExpression('/<option value="TISCH"\s+selected/', (string) $client->getResponse()->getContent());

        $client->request('GET', '/spieltisch/' . $id . '/zustand');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-bereich="punkte"', (string) $client->getResponse()->getContent());
    }

    public function testUmschaltenOhneBerechtigungWirdAbgelehnt(): void
    {
        [$client, $gast] = $this->angemeldet('gs_gast');
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $host = (new User())->setUsername('gs_host')->setEmail('gs_host@test.invalid');
        $em->persist($host);
        $em->flush();
        $beitritt = $c->get(TischBeitrittsService::class);
        $tisch = $beitritt->erstelleTisch($host, 'Fremder Tisch', ZugangsModusTyp::OFFEN, TischSteuerungsModus::NUR_ERSTELLER);
        $beitritt->beitreten($tisch, $gast);
        $em->clear();

        // Token als Ersteller in derselben Sitzung holen, dann als Gast posten.
        $client->loginUser($host);
        $crawler = $client->request('GET', '/spieltisch/' . $tisch->getId());
        $token = $crawler->filter('form[action$="/punkte-anzeige"] input[name="_token"]')->attr('value');

        $client->loginUser($gast);
        $crawler = $client->request('GET', '/spieltisch/' . $tisch->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/punkte-anzeige"]'), 'Ohne Berechtigung kein Umschalter.');

        $client->request('POST', '/spieltisch/' . $tisch->getId() . '/punkte-anzeige', ['_token' => $token, 'anzeige' => 'TISCH']);
        self::assertResponseStatusCodeSame(403);
        $antwort = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('nicht ändern', $antwort['fehler'], 'Abgelehnt wegen Berechtigung, nicht wegen des Tokens.');
    }

    public function testZustandNurFuerMitgliederDesTisches(): void
    {
        [$client, $fremd] = $this->angemeldet('gs_fremd');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $host = (new User())->setUsername('gs_besitzer')->setEmail('gs_besitzer@test.invalid');
        $em->persist($host);
        $em->flush();
        $tisch = static::getContainer()->get(TischBeitrittsService::class)
            ->erstelleTisch($host, 'Geschlossen', ZugangsModusTyp::OFFEN);

        $client->request('GET', '/spieltisch/' . $tisch->getId() . '/zustand?punkte=1');
        self::assertResponseStatusCodeSame(403);
    }

    public function testWerNachDemSpielGegangenIstSiehtNochDieAbrechnung(): void
    {
        $this->gegangenNachSpiel('gs_geher', new \DateTimeImmutable());
        self::assertResponseIsSuccessful();
    }

    public function testNachAblaufDerFristSiehtDerGegangeneNichtsMehr(): void
    {
        $this->gegangenNachSpiel('gs_spaet', new \DateTimeImmutable('-20 minutes'));
        self::assertResponseStatusCodeSame(403);
    }

    private function gegangenNachSpiel(string $name, \DateTimeImmutable $beendetAm): void
    {
        [$client, $geher] = $this->angemeldet($name);
        $c  = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $host = (new User())->setUsername($name . '_h')->setEmail($name . '_h@test.invalid');
        $em->persist($host);
        $em->flush();
        $beitritt = $c->get(TischBeitrittsService::class);
        $tisch = $beitritt->erstelleTisch($host, 'Abschied', ZugangsModusTyp::OFFEN);
        $beitritt->beitreten($tisch, $geher);

        // Beendetes Spiel, an dem der Gehende teilgenommen hat …
        $spiel = (new \App\Entity\Spiel())->setTisch($tisch)->setStatus(\App\Enum\SpielStatus::BEENDET)
            ->setBeendetAm($beendetAm);
        $em->persist($spiel);
        $em->persist((new \App\Entity\SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz(2)->setUser($geher)->setPunkteDelta(1));
        $em->flush();
        // … danach ist sein Tischplatz weg (wie bei „Nach Spiel verlassen“).
        $em->clear();
        $tisch = $c->get(TischRepository::class)->find($tisch->getId());
        $geher = $em->find(User::class, $geher->getId());
        self::assertInstanceOf(Tisch::class, $tisch);
        self::assertInstanceOf(User::class, $geher);
        $beitritt->verlassen($tisch, $geher);

        $client->request('GET', '/spieltisch/' . $tisch->getId() . '/zustand');
    }

    /** @return array{0: KernelBrowser, 1: User} */
    private function angemeldet(string $name): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())->setUsername($name)->setEmail($name . '@test.invalid');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return [$client, $user];
    }
}
