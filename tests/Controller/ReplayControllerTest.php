<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Entity\GespielteKarte;
use App\Entity\SpielTeilnehmer;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\ZugangsModusTyp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReplayControllerTest extends WebTestCase
{
    public function testReplaySeiteRendert(): void
    {
        $client = static::createClient();
        $c      = static::getContainer();
        $em     = $c->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('replay_view');
        $user->setEmail('replay_view@test.invalid');
        $em->persist($user);
        $em->flush();

        $tisch = $c->get(TischBeitrittsService::class)
            ->erstelleTisch($user, 'Replay-View', ZugangsModusTyp::OFFEN);

        $spiel = new \App\Entity\Spiel();
        $spiel->setTisch($tisch);
        $spiel->setStatus(SpielStatus::BEENDET);
        $spiel->setVariante(SpielVariante::NORMALSPIEL);
        $spiel->setBeendetAm(new \DateTimeImmutable());
        $spiel->setWertungDetails([
            'augen'     => ['RE' => 121, 'KONTRA' => 119],
            'positionen' => [['label' => 'Gewonnen', 'team' => 'RE', 'punkte' => 1]],
            'sieger'    => 'RE',
            'spielwert' => 1,
        ]);
        $em->persist($spiel);

        foreach ([1, 2, 3, 4] as $sitz) {
            $t = new SpielTeilnehmer();
            $t->setSpiel($spiel);
            $t->setSitzplatz($sitz);
            $t->setTeam($sitz % 2 === 1 ? Team::RE : Team::KONTRA);
            $t->setPunkteDelta($sitz % 2 === 1 ? 1 : -1);
            $t->setGewonnen($sitz % 2 === 1);
            if ($sitz === 1) {
                $t->setUser($user);
            } else {
                $t->setIstBot(true);
                $t->setBotName('Bot' . $sitz);
            }
            $em->persist($t);
        }

        $stich = [1 => 'KREUZ_ASS_1', 2 => 'KREUZ_ZEHN_1', 3 => 'KARO_NEUN_1', 4 => 'KREUZ_KOENIG_1'];
        $pos   = 1;
        foreach ($stich as $sitz => $karteId) {
            $gk = new GespielteKarte();
            $gk->setSpiel($spiel);
            $gk->setSitzplatz($sitz);
            $gk->setStichNr(1);
            $gk->setPositionImStich($pos++);
            $gk->setKarteId($karteId);
            $em->persist($gk);
        }
        $em->flush();

        $client->loginUser($user);

        // Liste
        $client->request('GET', '/replays');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Meine Replays');

        // Viewer
        $client->request('GET', '/replays/' . $spiel->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="replay"]');
        self::assertSelectorExists('[data-replay-target="svgPool"]');
    }
}
