<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\BotZugService;
use App\Application\Doppelkopf\ChatService;
use App\Application\Doppelkopf\SpielAbschlussService;
use App\Application\Doppelkopf\SpielStartService;
use App\Entity\Spiel;
use App\Enum\SpielStatus;
use App\Enum\ZugangsModusTyp;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielRepository;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Spielt ein komplettes Spiel mit vier Bots durch (wie der Tisch-Worker) und
 * prüft, dass es korrekt persistiert wird: Status BEENDET, alle 48 Karten,
 * Teilnehmer mit Punkte-Delta/Gewinn und eine Wertungsabrechnung.
 */
final class VollesSpielPersistenzTest extends DoppelkopfIntegrationTestCase
{
    public function testKomplettesBotSpielWirdGespeichert(): void
    {
        $host  = $this->neuerUser('voll_host');
        $tisch = $this->beitritt->erstelleTisch($host, 'Volles-Spiel', ZugangsModusTyp::OFFEN);
        $tischId = $tisch->getId();
        $hostId  = $host->getId();

        // Frisch laden, damit die aktiveSpieler-Collection des Tisches gefüllt ist
        // (setTisch synct die inverse Collection nicht — siehe Basisklasse).
        [$tisch, $host] = $this->frischLaden($tischId, $hostId);

        // Drei Bots auffüllen → vier aktive Spieler (Host + 3 Bots).
        $bots = $this->beitritt->botsAuffuellen($tisch, $host);
        self::assertSame(3, $bots);

        // Erneut frisch laden, damit der Tisch alle vier Spieler kennt.
        [$tisch, $host] = $this->frischLaden($tischId, $hostId);

        $start = static::getContainer()->get(SpielStartService::class);
        $spiel = $start->starten($tisch);
        $spielId = $spiel->getId();

        // Den Host für die Vorbehaltsrunde ebenfalls als Bot deklarieren lassen,
        // damit der Durchlauf ohne menschliche Eingabe komplett automatisch läuft.
        $botZug    = static::getContainer()->get(BotZugService::class);
        $abschluss = static::getContainer()->get(SpielAbschlussService::class);

        // Worker-Schleife nachbilden: jeweils der Spieler am Zug handelt,
        // fälliger Abschluss wird ausgeführt.
        for ($i = 0; $i < 400; $i++) {
            $this->em->refresh($spiel);

            if ($spiel->getStatus() === SpielStatus::BEENDET) {
                break;
            }

            if ($spiel->getAbschlussFaelligAm() !== null) {
                $abschluss->abschliessen($spiel);
                continue;
            }

            $sitz = $spiel->getAktuellerSpielerSitzplatz();
            $botZug->spielenFuerSitzplatz($spiel, $sitz);
        }

        // Frisch aus der DB laden und Persistenz prüfen.
        $this->em->clear();
        $spielRepo = static::getContainer()->get(SpielRepository::class);
        $karteRepo = static::getContainer()->get(GespielteKarteRepository::class);

        $gespeichert = $spielRepo->find($spielId);
        self::assertInstanceOf(Spiel::class, $gespeichert);
        self::assertSame(SpielStatus::BEENDET, $gespeichert->getStatus(), 'Spiel sollte beendet sein');
        self::assertNotNull($gespeichert->getBeendetAm());
        self::assertNotNull($gespeichert->getVariante());

        // 48 Karten (12 Stiche × 4) bei Standardregeln.
        $karten = $karteRepo->findBy(['spiel' => $gespeichert]);
        self::assertCount(48, $karten, 'Alle 48 Karten sollten gespeichert sein');

        // Vier Teilnehmer mit ausgewerteter Abrechnung.
        self::assertCount(4, $gespeichert->getTeilnehmer());
        foreach ($gespeichert->getTeilnehmer() as $t) {
            self::assertNotNull($t->getTeam(), 'Team sollte feststehen');
            self::assertNotNull($t->getPunkteDelta(), 'Punkte-Delta sollte gesetzt sein');
            self::assertNotNull($t->isGewonnen(), 'Gewonnen-Status sollte gesetzt sein');
        }

        // Detaillierte Wertung muss vorliegen.
        $w = $gespeichert->getWertungDetails();
        self::assertIsArray($w);
        self::assertArrayHasKey('augen', $w);
        self::assertArrayHasKey('sieger', $w);
        self::assertSame(240, $w['augen']['RE'] + $w['augen']['KONTRA'], 'Augensumme aller Stiche = 240');

        // Das Spielende muss als System-Event im Tisch-Chat protokolliert sein.
        $chat = static::getContainer()->get(ChatService::class);
        $systemTexte = array_map(
            static fn($n) => $n->getText(),
            array_filter($chat->verlauf($gespeichert->getTisch(), 200), static fn($n) => $n->istSystem()),
        );
        $ergebnisZeilen = array_filter($systemTexte, static fn(string $t) => str_starts_with($t, 'Spiel beendet:'));
        self::assertCount(1, $ergebnisZeilen, 'Genau eine Spielergebnis-Zeile im Event-Log');
    }
}
