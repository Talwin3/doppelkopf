<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Application\SystemEinstellungService;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gesamtkonto Stufe 3 im Browser-Durchlauf: Seite /bestenlisten und der Profil-Schalter.
 */
final class BestenlisteSeiteTest extends WebTestCase
{
    public function testSeiteZeigtKategorieUndZeitraumUndEigenenStand(): void
    {
        $client = static::createClient();
        $c = static::getContainer();
        $c->get(SystemEinstellungService::class)->set('bestenliste_min_spiele_jahr', '1');
        $em = $c->get(EntityManagerInterface::class);

        // Alt-Datensatz ohne Seed: Die Liste muss trotzdem einen Avatar bauen können.
        $gelistet = $this->user($em, 'bs_oben')->setAvatarSeed('');
        $versteckt = $this->user($em, 'bs_versteckt')->setInBestenlisten(false);
        $em->flush();

        $tisch = $c->get(TischBeitrittsService::class)->erstelleTisch($gelistet, 'Seite', ZugangsModusTyp::OFFEN);
        $spiel = (new Spiel())->setTisch($tisch)->setStatus(SpielStatus::BEENDET)
            ->setKategorie(WertungsKategorie::OHNE_NEUNER)->setGlobalGewertet(true)
            ->setBeendetAm(new \DateTimeImmutable('2026-03-10 18:00', new \DateTimeZone('UTC')))
            ->setWertungDetails(['spielwert' => 3]);
        $em->persist($spiel);
        $em->persist((new SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz(1)->setUser($gelistet)->setPunkteDelta(9));
        $em->persist((new SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz(2)->setUser($versteckt)->setPunkteDelta(-3));
        $em->flush();

        $client->loginUser($versteckt);
        $client->request('GET', '/bestenlisten?kategorie=OHNE_NEUNER&zeitraum=jahr&jahr=2026&sortierung=punkte');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('h2', '2026');
        self::assertSelectorTextContains('table', 'bs_oben');
        self::assertSelectorTextContains('table', '+9');
        self::assertSelectorTextNotContains('table', 'bs_versteckt');
        self::assertSelectorTextContains('body', 'Du erscheinst in keiner Bestenliste');

        self::assertStringNotContainsString('/avatar/lorelei//', (string) $client->getResponse()->getContent());

        // Aus einem vergangenen Jahr führt der Monatsreiter in dessen Dezember.
        $crawler = $client->request('GET', '/bestenlisten?zeitraum=jahr&jahr=2025');
        $monat = $crawler->filter('nav[aria-label="Zeitraum"] a')->first()->attr('href');
        self::assertStringContainsString('jahr=2025', (string) $monat);
        self::assertStringContainsString('monat=12', (string) $monat);

        // Eine andere Kategorie ist leer.
        $client->request('GET', '/bestenlisten?kategorie=DDV&zeitraum=jahr&jahr=2026');
        self::assertSelectorNotExists('table');
    }

    public function testSeiteGehtAuchOhneAnmeldungUndMitUnsinnParametern(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bestenlisten?kategorie=xyz&zeitraum=monat&jahr=abc&monat=99');
        self::assertResponseIsSuccessful();
    }

    public function testProfilSchalterNichtInBestenlisten(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->user($em, 'bs_schalter');
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/einstellungen');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/einstellungen/datenschutz"]')->form();
        $form['in_bestenlisten']->untick();
        $client->submit($form);

        $em->clear();
        $neu = $em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $neu);
        self::assertTrue($neu->isProfilOeffentlich());
        self::assertFalse($neu->isInBestenlisten());
        self::assertFalse($neu->isGelistet());
    }

    private function user(EntityManagerInterface $em, string $name): User
    {
        $user = (new User())->setUsername($name)->setEmail($name . '@test.invalid');
        $em->persist($user);

        return $user;
    }
}
