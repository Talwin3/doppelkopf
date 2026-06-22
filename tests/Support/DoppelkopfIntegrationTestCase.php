<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Application\ProfilService;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Repository\TischRepository;
use App\Repository\TischSpielerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Basis für Doppelkopf-Integrationstests: bootet den Kernel, stellt die häufig
 * gebrauchten Services und Helfer bereit. Läuft gegen die Test-DB; dama/doctrine-test-bundle
 * rollt jeden Test zurück. Siehe tests/bootstrap.php zur APP_ENV/DATABASE_URL-Erzwingung.
 */
abstract class DoppelkopfIntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected TischBeitrittsService $beitritt;
    protected ProfilService $profil;
    protected TischRepository $tischRepo;
    protected TischSpielerRepository $tischSpielerRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->beitritt = $c->get(TischBeitrittsService::class);
        $this->profil = $c->get(ProfilService::class);
        $this->tischRepo = $c->get(TischRepository::class);
        $this->tischSpielerRepo = $c->get(TischSpielerRepository::class);
    }

    protected function neuerUser(string $name): User
    {
        $user = new User();
        $user->setUsername($name);
        $user->setEmail($name . '@test.invalid');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function laufendesSpiel(Tisch $tisch): Spiel
    {
        return $this->spielMitStatus($tisch, SpielStatus::LAUFEND);
    }

    protected function beendetesSpiel(Tisch $tisch): Spiel
    {
        return $this->spielMitStatus($tisch, SpielStatus::BEENDET);
    }

    protected function spielMitStatus(Tisch $tisch, SpielStatus $status): Spiel
    {
        $spiel = new Spiel();
        $spiel->setTisch($tisch);
        $spiel->setStatus($status);

        $this->em->persist($spiel);
        $this->em->flush();

        return $spiel;
    }

    /**
     * Leert den EntityManager und lädt Tisch + User frisch aus der DB —
     * simuliert einen frischen HTTP-Request mit lazy geladener Spieler-Collection.
     * (setTisch() synct die inverse Collection nicht; ohne Reload wäre sie stale.)
     *
     * @return array{0: Tisch, 1: User}
     */
    protected function frischLaden(Uuid $tischId, Uuid $userId): array
    {
        $this->em->clear();

        $tisch = $this->tischRepo->find($tischId);
        $user = $this->em->getRepository(User::class)->find($userId);

        self::assertInstanceOf(Tisch::class, $tisch);
        self::assertInstanceOf(User::class, $user);

        return [$tisch, $user];
    }

    /** @return list<string> RFC-4122-IDs aller aktuell als menschenlos markierten Tische. */
    protected function menschenloseTischIds(): array
    {
        return array_map(
            static fn(Tisch $t): string => $t->getId()->toRfc4122(),
            $this->tischRepo->findMenschenlose(),
        );
    }
}
