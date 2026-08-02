<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Entity\User;
use App\Enum\TischStatus;
use App\Enum\ZugangsModusTyp;
use App\Repository\TischRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Kachel „Aktive Tische" im Admin-Dashboard. Die Tests rechnen bewusst mit
 * Differenzen zum Ausgangsbestand, statt eine leere Tabelle vorauszusetzen.
 */
final class AdminDashboardTest extends WebTestCase
{
    public function testZaehlungIgnoriertBeendeteTische(): void
    {
        self::createClient();
        $c        = static::getContainer();
        $em       = $c->get(EntityManagerInterface::class);
        $repo     = $c->get(TischRepository::class);
        $beitritt = $c->get(TischBeitrittsService::class);

        $vorher = $repo->zaehleAktive();

        $beitritt->erstelleTisch($this->neuerUser($em, 'dash_wartend'), 'Dash-Wartend', ZugangsModusTyp::OFFEN);

        $laufend = $beitritt->erstelleTisch($this->neuerUser($em, 'dash_laufend'), 'Dash-Laufend', ZugangsModusTyp::OFFEN);
        $laufend->setStatus(TischStatus::LAUFEND);

        $beendet = $beitritt->erstelleTisch($this->neuerUser($em, 'dash_beendet'), 'Dash-Beendet', ZugangsModusTyp::OFFEN);
        $beendet->setStatus(TischStatus::BEENDET);
        $em->flush();

        // Der beendete Tisch zählt nicht mit.
        self::assertSame($vorher + 2, $repo->zaehleAktive());
    }

    public function testKachelZeigtDieZahlStattDesPlatzhalters(): void
    {
        $client = static::createClient();
        $c      = static::getContainer();
        $em     = $c->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->setUsername('dash_admin');
        $admin->setEmail('dash_admin@test.invalid');
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $erwartet = $c->get(TischRepository::class)->zaehleAktive();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $kachel = $crawler->filter('div.rounded-lg')->reduce(
            static fn($node) => str_contains($node->text(), 'Aktive Tische'),
        );
        self::assertCount(1, $kachel, 'Kachel „Aktive Tische" nicht gefunden.');

        $text = $kachel->text();
        self::assertStringNotContainsString('verfügbar ab Phase', $text, 'Platzhalter steht noch drin.');
        self::assertMatchesRegularExpression(
            '/Aktive Tische\s+' . $erwartet . '\b/u',
            $text,
            'Kachel zeigt nicht die Zahl aus dem Repository.',
        );
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
