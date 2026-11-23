<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gesamtkonto Stufe 4 im Browser-Durchlauf: Profilseite mit Kategorie, Zeitraum und Filter.
 */
final class ProfilStatistikSeiteTest extends WebTestCase
{
    public function testProfilZeigtStatistikJeKategorieUndZeitraum(): void
    {
        $client = static::createClient();
        $c  = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $user = (new User())->setUsername('pss_anna')->setEmail('pss_anna@test.invalid');
        $em->persist($user);
        $em->flush();
        $tisch = $c->get(TischBeitrittsService::class)->erstelleTisch($user, 'Profil', ZugangsModusTyp::OFFEN);

        foreach ([[WertungsKategorie::DDV, SpielVariante::SOLO_DAMEN, 9], [WertungsKategorie::OHNE_NEUNER, SpielVariante::NORMALSPIEL, -2]] as [$kat, $variante, $punkte]) {
            $spiel = (new Spiel())->setTisch($tisch)->setStatus(SpielStatus::BEENDET)->setKategorie($kat)
                ->setVariante($variante)->setBeendetAm(new \DateTimeImmutable('2026-03-10 18:00'))
                ->setWertungDetails(['spielwert' => abs($punkte)]);
            $em->persist($spiel);
            $em->persist((new SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz(1)->setUser($user)
                ->setTeam(Team::RE)->setGewonnen($punkte > 0)->setPunkteDelta($punkte));
        }
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/profil/pss_anna');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Re und Kontra');
        self::assertSelectorTextContains('body', 'Damen-Solo');
        self::assertSelectorTextContains('body', 'Ansagen');

        $client->request('GET', '/profil/pss_anna?kategorie=OHNE_NEUNER&zeitraum=jahr&jahr=2026');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Statistiken 2026 · Ohne Neuner');
        self::assertSelectorTextNotContains('body', 'Damen-Solo');

        // Aus einem Monat führt der Reiter „Jahr“ in dessen Jahr, aus einem Jahr „Monat“ in dessen Dezember.
        $crawler = $client->request('GET', '/profil/pss_anna?zeitraum=monat&jahr=2025&monat=3');
        $reiter = $crawler->filter('nav[aria-label="Zeitraum"] a');
        self::assertStringContainsString('jahr=2025', (string) $reiter->eq(1)->attr('href'));
        $crawler = $client->request('GET', '/profil/pss_anna?zeitraum=jahr&jahr=2025');
        self::assertStringContainsString('monat=12', (string) $crawler->filter('nav[aria-label="Zeitraum"] a')->eq(2)->attr('href'));

        // Nur gewertete: hier keins.
        $client->request('GET', '/profil/pss_anna?gewertet=1');
        self::assertSelectorTextContains('body', 'Keine abgeschlossenen Spiele in dieser Auswahl');

        // Unsinn in den Parametern schadet nicht.
        $client->request('GET', '/profil/pss_anna?kategorie=x&zeitraum=monat&jahr=abc&monat=77');
        self::assertResponseIsSuccessful();
    }
}
