<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Spiel;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Testet den Lösch-Pfad des Tisch-Workers (app:bot:spielzuge --einmalig):
 *  - menschenloser Tisch ohne laufendes Spiel wird gelöscht, Tisch-Sitzplätze per ON-DELETE-CASCADE mit
 *  - beendete Spiele bleiben als Replay erhalten (tisch_id wird per ON DELETE SET NULL entkoppelt)
 *  - menschenloser Tisch MIT laufendem Spiel bleibt erhalten (Bots spielen durch)
 *
 * Ergänzt {@see \App\Tests\Application\Doppelkopf\TischLifecycleTest}, der nur die Markierung prüft.
 * Der Worker läuft normal als Dauerschleife; --einmalig führt genau einen Durchlauf aus.
 */
final class BotWorkerCleanupTest extends DoppelkopfIntegrationTestCase
{
    public function testWorkerLoeschtMenschenlosenTischOhneLaufendesSpiel(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Verwaister Tisch', ZugangsModusTyp::OFFEN);
        $tischId = $tisch->getId();
        $aliceId = $alice->getId();

        // Nur-Bot-Tisch vorbereiten und ein bereits beendetes Spiel als Kind-Entität anlegen.
        [$tisch, $alice] = $this->frischLaden($tischId, $aliceId);
        $this->beitritt->botsAuffuellen($tisch, $alice);
        $spielId = $this->beendetesSpiel($tisch)->getId();

        // Alice verlässt → Tisch ist menschenlos (nur noch Bots), kein laufendes Spiel.
        [$tisch, $alice] = $this->frischLaden($tischId, $aliceId);
        $this->beitritt->verlassen($tisch, $alice);
        self::assertContains($tischId->toRfc4122(), $this->menschenloseTischIds(), 'Vorbedingung: Tisch ist menschenlos');

        $this->workerEinmalAusfuehren();

        self::assertNull(
            $this->tischRepo->find($tischId),
            'Der Worker muss den menschenlosen Tisch ohne laufendes Spiel löschen',
        );
        // Das beendete Spiel muss als Replay erhalten bleiben, aber vom gelöschten
        // Tisch entkoppelt sein (ON DELETE SET NULL statt CASCADE).
        $this->em->clear();
        $ueberlebendesSpiel = $this->em->getRepository(Spiel::class)->find($spielId);
        self::assertNotNull(
            $ueberlebendesSpiel,
            'Das beendete Spiel muss als Replay erhalten bleiben (kein CASCADE mehr)',
        );
        self::assertNull(
            $ueberlebendesSpiel->getTisch(),
            'Der Tisch-Bezug des Spiels muss per ON DELETE SET NULL gelöst werden',
        );
        self::assertSame(
            0,
            $this->tischSpielerCount($tischId),
            'Die Bot-Sitzplätze des Tisches müssen per ON DELETE CASCADE mitgelöscht werden',
        );
    }

    public function testWorkerLoeschtMenschenlosenTischMitLaufendemSpielNicht(): void
    {
        $alice = $this->neuerUser('alice');
        $tisch = $this->beitritt->erstelleTisch($alice, 'Spielender Tisch', ZugangsModusTyp::OFFEN);
        $tischId = $tisch->getId();
        $aliceId = $alice->getId();

        [$tisch, $alice] = $this->frischLaden($tischId, $aliceId);
        $this->beitritt->botsAuffuellen($tisch, $alice);
        $this->laufendesSpiel($tisch);

        // Konto-Löschung markiert den Tisch als menschenlos, OHNE das laufende Spiel zu stoppen
        // (Bots spielen durch — realistischer „Mensch weg mitten im Spiel"-Fall).
        [$tisch, $alice] = $this->frischLaden($tischId, $aliceId);
        $this->profil->kontoLoeschen($alice);
        self::assertContains($tischId->toRfc4122(), $this->menschenloseTischIds(), 'Vorbedingung: menschenlos markiert');

        $this->workerEinmalAusfuehren();

        self::assertNotNull(
            $this->tischRepo->find($tischId),
            'Ein menschenloser Tisch mit laufendem Spiel darf NICHT gelöscht werden (Bots spielen durch)',
        );
    }

    private function workerEinmalAusfuehren(): void
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:bot:spielzuge'),
        );
        $tester->execute(['--einmalig' => true]);
        $tester->assertCommandIsSuccessful();
    }

    private function tischSpielerCount(Uuid $tischId): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(ts.id) FROM App\Entity\TischSpieler ts WHERE IDENTITY(ts.tisch) = :tisch',
        )->setParameter('tisch', $tischId->toRfc4122())->getSingleScalarResult();
    }
}
