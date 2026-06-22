<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\TischBeitrittsService;
use App\Application\ProfilService;
use App\Domain\Doppelkopf\Exception\TischVerlassenGesperrtException;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\ZugangsModusTyp;
use App\Repository\TischRepository;
use App\Repository\TischSpielerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integrationstests für die Tisch-Lifecycle-Logik aus Commit e4cac0b:
 *  - Verlassen-Sperre für sitzende Spieler bei laufendem Spiel
 *  - „menschenlos"-Markierung, wenn der letzte Mensch geht (sonst räumt der Worker nie auf)
 *  - dieselbe Markierung bei Konto-Löschung
 *
 * Läuft gegen eine echte Test-DB (doppelkopf_test). dama/doctrine-test-bundle kapselt
 * jeden Test in eine Transaktion und rollt sie danach zurück → vollständige Isolation.
 *
 * Wichtig: Vor der zu testenden Aktion wird der EntityManager geleert und der Tisch
 * frisch geladen — so verhält sich die (lazy) Spieler-Collection wie in einem echten
 * HTTP-Request. Sonst wäre die In-Memory-Collection leer (setTisch() synct die
 * inverse Seite nicht) und der Test würde am eigentlichen Bug vorbeilaufen.
 */
final class TischLifecycleTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TischBeitrittsService $beitritt;
    private ProfilService $profil;
    private TischRepository $tischRepo;
    private TischSpielerRepository $tischSpielerRepo;

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

    public function testSitzenderSpielerKannBeiLaufendemSpielNichtVerlassen(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Sperr-Tisch', ZugangsModusTyp::OFFEN);
        $this->laufendesSpiel($tisch);

        [$tisch, $alice] = $this->frischLaden($tisch->getId(), $alice->getId());

        $this->expectException(TischVerlassenGesperrtException::class);
        $this->beitritt->verlassen($tisch, $alice);
    }

    public function testWarteschlangenSpielerDarfWaehrendLaufendemSpielVerlassen(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Wartelisten-Tisch', ZugangsModusTyp::OFFEN);
        foreach (['bob', 'carol', 'dave'] as $name) {
            $this->beitritt->beitreten($tisch, $this->neuerUser($name));
        }
        $eve = $this->neuerUser('eve');
        $eveSpieler = $this->beitritt->beitreten($tisch, $eve); // 5. Spieler → Warteschlange
        self::assertFalse($eveSpieler->istAktiv(), 'Eve sollte in der Warteschlange sein (kein Sitzplatz)');

        $this->laufendesSpiel($tisch);

        [$tisch, $eve] = $this->frischLaden($tisch->getId(), $eve->getId());

        // Darf NICHT werfen — Warteschlangen-Spieler dürfen auch während eines Spiels gehen.
        $this->beitritt->verlassen($tisch, $eve);

        self::assertNull(
            $this->tischSpielerRepo->findByTischAndUser($tisch, $eve),
            'Eve sollte den Tisch verlassen haben',
        );
        self::assertNull(
            $tisch->getMenschenloseSeitAm(),
            'Tisch hat noch sitzende Menschen → nicht menschenlos',
        );
    }

    public function testLetzterMenschVerlaesstMarkiertTischAlsMenschenlos(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Verwaister Tisch', ZugangsModusTyp::OFFEN);
        self::assertNull($tisch->getMenschenloseSeitAm(), 'Vorbedingung: noch nicht menschenlos');

        $tischId = $tisch->getId();
        [$tisch, $alice] = $this->frischLaden($tischId, $alice->getId());

        $this->beitritt->verlassen($tisch, $alice);

        self::assertNotNull(
            $tisch->getMenschenloseSeitAm(),
            'Nach dem Weggang des letzten Menschen muss menschenloseSeitAm gesetzt sein',
        );
        self::assertContains(
            $tischId->toRfc4122(),
            $this->menschenloseTischIds(),
            'findMenschenlose() muss den verwaisten Tisch zurückgeben (sonst räumt der Worker ihn nie auf)',
        );
    }

    public function testTischMitVerbleibendemMenschBleibtNichtMenschenlos(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Belebter Tisch', ZugangsModusTyp::OFFEN);
        $bob = $this->neuerUser('bob');
        $this->beitritt->beitreten($tisch, $bob);

        $tischId = $tisch->getId();
        [$tisch, $bob] = $this->frischLaden($tischId, $bob->getId());

        // Kein laufendes Spiel → Verlassen erlaubt; Alice bleibt sitzen.
        $this->beitritt->verlassen($tisch, $bob);

        self::assertNull(
            $tisch->getMenschenloseSeitAm(),
            'Solange Alice sitzt, darf der Tisch nicht als menschenlos markiert werden',
        );
        self::assertNotContains($tischId->toRfc4122(), $this->menschenloseTischIds());
    }

    public function testKontoLoeschenMarkiertVerwaistenTischAlsMenschenlos(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Lösch-Tisch', ZugangsModusTyp::OFFEN);

        $tischId = $tisch->getId();
        [$tisch, $alice] = $this->frischLaden($tischId, $alice->getId());

        $this->profil->kontoLoeschen($alice);

        self::assertNotNull(
            $tisch->getMenschenloseSeitAm(),
            'Konto-Löschung des letzten Menschen muss den Tisch als menschenlos markieren',
        );
        self::assertNull(
            $this->tischSpielerRepo->findByTischAndUser($tisch, $alice),
            'Der Sitzplatz des gelöschten Kontos muss freigegeben sein',
        );
        self::assertContains($tischId->toRfc4122(), $this->menschenloseTischIds());
    }

    // ── Helfer ───────────────────────────────────────────────────────────────

    private function neuerUser(string $name): User
    {
        $user = new User();
        $user->setUsername($name);
        $user->setEmail($name . '@test.invalid');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function laufendesSpiel(Tisch $tisch): Spiel
    {
        $spiel = new Spiel();          // Status ist per Default SpielStatus::LAUFEND
        $spiel->setTisch($tisch);

        $this->em->persist($spiel);
        $this->em->flush();

        return $spiel;
    }

    /**
     * Leert den EntityManager und lädt Tisch + User frisch aus der DB —
     * simuliert einen frischen HTTP-Request mit lazy geladener Spieler-Collection.
     *
     * @return array{0: Tisch, 1: User}
     */
    private function frischLaden(Uuid $tischId, Uuid $userId): array
    {
        $this->em->clear();

        $tisch = $this->tischRepo->find($tischId);
        $user = $this->em->getRepository(User::class)->find($userId);

        self::assertInstanceOf(Tisch::class, $tisch);
        self::assertInstanceOf(User::class, $user);

        return [$tisch, $user];
    }

    /** @return list<string> RFC-4122-IDs aller aktuell als menschenlos markierten Tische. */
    private function menschenloseTischIds(): array
    {
        return array_map(
            static fn(Tisch $t): string => $t->getId()->toRfc4122(),
            $this->tischRepo->findMenschenlose(),
        );
    }
}
