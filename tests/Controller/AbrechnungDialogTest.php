<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\ZugangsModusTyp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Stellt sicher, dass die Abrechnung des letzten Spiels auf der Spieltisch-Seite
 * als (vom spieltisch-Controller bei SPIEL_BEENDET auto-geöffneter) Dialog
 * gerendert wird.
 */
final class AbrechnungDialogTest extends WebTestCase
{
    public function testAbrechnungWirdAlsDialogGerendert(): void
    {
        $client = static::createClient();
        $c      = static::getContainer();
        $em     = $c->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('abr_view');
        $user->setEmail('abr_view@test.invalid');
        $em->persist($user);
        $em->flush();

        /** @var Tisch $tisch */
        $tisch = $c->get(TischBeitrittsService::class)
            ->erstelleTisch($user, 'Abr-Tisch', ZugangsModusTyp::OFFEN);
        $tischId = $tisch->getId();

        // Beendetes Spiel am Tisch (wird zu letztesSpiel).
        $spiel = new Spiel();
        $spiel->setTisch($tisch);
        $spiel->setStatus(SpielStatus::BEENDET);
        $spiel->setVariante(SpielVariante::NORMALSPIEL);
        $spiel->setBeendetAm(new \DateTimeImmutable());
        $spiel->setWertungDetails([
            'augen'      => ['RE' => 130, 'KONTRA' => 110],
            'positionen' => [['label' => 'Gewonnen', 'team' => 'RE', 'punkte' => 1]],
            'sieger'     => 'RE',
            'spielwert'  => 1,
        ]);
        $em->persist($spiel);

        foreach ([1, 2, 3, 4] as $sitz) {
            $t = new SpielTeilnehmer();
            $t->setSpiel($spiel);
            $t->setSitzplatz($sitz);
            $t->setTeam($sitz % 2 === 1 ? Team::RE : Team::KONTRA);
            $t->setGewonnen($sitz % 2 === 1);
            $t->setPunkteDelta($sitz % 2 === 1 ? 1 : -1);
            if ($sitz === 1) {
                $t->setUser($user);
            } else {
                $t->setIstBot(true);
                $t->setBotName('Bot' . $sitz);
            }
            $em->persist($t);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        $client->request('GET', '/spieltisch/' . $tischId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="dialog"]');
        self::assertSelectorExists('dialog[data-spieltisch-target="abrechnungDialog"]');
        // Die Abrechnungsinhalte sind im Dialog vorhanden.
        self::assertSelectorTextContains('dialog[data-dialog-target="dialog"]', 'Abrechnung');
    }
}
