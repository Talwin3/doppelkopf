<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\ReplayService;
use App\Entity\GespielteKarte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\ZugangsModusTyp;
use App\Repository\SpielRepository;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

final class ReplayServiceTest extends DoppelkopfIntegrationTestCase
{
    public function testBaueDatenLiefertStichGewinnerUndStruktur(): void
    {
        $ersteller = $this->neuerUser('replay_host');
        $tisch     = $this->beitritt->erstelleTisch($ersteller, 'Replay-Tisch', ZugangsModusTyp::OFFEN);

        $spiel = $this->beendetesSpiel($tisch);
        $spiel->setVariante(SpielVariante::NORMALSPIEL);

        // 4 Teilnehmer: Sitzplatz 1 = Betrachter (Mensch), Rest Bots.
        foreach ([1, 2, 3, 4] as $sitz) {
            $t = new SpielTeilnehmer();
            $t->setSpiel($spiel);
            $t->setSitzplatz($sitz);
            $t->setTeam($sitz % 2 === 1 ? Team::RE : Team::KONTRA);
            if ($sitz === 1) {
                $t->setUser($ersteller);
            } else {
                $t->setIstBot(true);
                $t->setBotName('Bot' . $sitz);
            }
            $this->em->persist($t);
        }

        // Ein vollständiger Stich (Normalspiel, Karo = Trumpf):
        // Sitz1 Kreuz-Ass (Anspiel, Fehlfarbe), Sitz2 Kreuz-Zehn, Sitz3 Karo-Neun (Trumpf!),
        // Sitz4 Kreuz-König → Trumpf sticht → Gewinner Sitz3. Augen: 11+10+0+4 = 25.
        $stich = [
            1 => 'KREUZ_ASS_1',
            2 => 'KREUZ_ZEHN_1',
            3 => 'KARO_NEUN_1',
            4 => 'KREUZ_KOENIG_1',
        ];
        $pos = 1;
        foreach ($stich as $sitz => $karteId) {
            $gk = new GespielteKarte();
            $gk->setSpiel($spiel);
            $gk->setSitzplatz($sitz);
            $gk->setStichNr(1);
            $gk->setPositionImStich($pos++);
            $gk->setKarteId($karteId);
            $this->em->persist($gk);
        }

        $this->em->flush();
        $spielId = $spiel->getId();
        $userId  = $ersteller->getId();
        $this->em->clear();

        // Frisch laden, damit die Collections aus der DB kommen.
        $spielRepo = static::getContainer()->get(SpielRepository::class);
        $spiel     = $spielRepo->find($spielId);
        $betrachter = $this->em->getRepository(\App\Entity\User::class)->find($userId);

        $service = static::getContainer()->get(ReplayService::class);
        $daten   = $service->baueDaten($spiel, $betrachter);

        self::assertSame(1, $daten['meinSitzplatz']);
        self::assertSame('NORMALSPIEL', $daten['variante']);
        self::assertCount(4, $daten['spieler']);
        self::assertCount(1, $daten['stiche']);

        $ersterStich = $daten['stiche'][0];
        self::assertSame(1, $ersterStich['leadSeat']);
        self::assertSame(3, $ersterStich['winnerSeat'], 'Karo-Neun (Trumpf) gewinnt den Stich');
        self::assertSame(25, $ersterStich['augen']);
        self::assertCount(4, $ersterStich['karten']);
        self::assertCount(4, $daten['kartenIds']);
    }
}
