<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\SpielAbschlussService;
use App\Application\Doppelkopf\SpielTypResolver;
use App\Domain\Doppelkopf\ValueObject\Kartenstapel;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\VorbehaltTyp;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Stille Hochzeit (TSR 4.4.5): Wer beide Kreuz-Damen hält und trotzdem „gesund"
 * meldet, spielt allein gegen drei und wird wie ein Solist abgerechnet – bleibt
 * aber ein Normalspiel und wird nicht vorab im Event-Log verraten.
 */
final class StilleHochzeitTest extends DoppelkopfIntegrationTestCase
{
    public function testBeideKreuzDamenUndGesundErgibtSoloWertung(): void
    {
        $spiel = $this->spielMitAllenGesund(beideKreuzDamenBei: 1);

        static::getContainer()->get(SpielTypResolver::class)->aufloesen($spiel);
        $this->em->refresh($spiel);

        self::assertSame(SpielVariante::NORMALSPIEL, $spiel->getVariante(), 'Bleibt ein Normalspiel.');
        self::assertTrue($spiel->isHochzeitAlsSolo(), 'Muss als Solo abgerechnet werden.');

        // Einer gegen drei.
        self::assertSame(Team::RE, $spiel->getTeilnehmerBySitzplatz(1)?->getTeam());
        foreach ([2, 3, 4] as $sitz) {
            self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz($sitz)?->getTeam());
        }
    }

    public function testNormaleKreuzDamenVerteilungBleibtNormalspiel(): void
    {
        $spiel = $this->spielMitAllenGesund(beideKreuzDamenBei: null);

        static::getContainer()->get(SpielTypResolver::class)->aufloesen($spiel);
        $this->em->refresh($spiel);

        self::assertSame(SpielVariante::NORMALSPIEL, $spiel->getVariante());
        self::assertFalse($spiel->isHochzeitAlsSolo(), 'Ohne Doppel-Dame keine Solo-Wertung.');

        // Zwei gegen zwei.
        self::assertSame(Team::RE, $spiel->getTeilnehmerBySitzplatz(1)?->getTeam());
        self::assertSame(Team::RE, $spiel->getTeilnehmerBySitzplatz(2)?->getTeam());
        self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz(3)?->getTeam());
        self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz(4)?->getTeam());
    }

    public function testDieStilleHochzeitWirdNichtVorabImProtokollVerraten(): void
    {
        $spiel = $this->spielMitAllenGesund(beideKreuzDamenBei: 1);
        $tisch = $spiel->getTisch();

        static::getContainer()->get(SpielTypResolver::class)->aufloesen($spiel);

        $verlauf = static::getContainer()->get(\App\Repository\ChatNachrichtRepository::class)
            ->findeLetzteFuerTisch($tisch);

        foreach ($verlauf as $nachricht) {
            self::assertStringNotContainsStringIgnoringCase('hochzeit', $nachricht->getText());
            self::assertStringNotContainsStringIgnoringCase('solo', $nachricht->getText());
        }
    }

    public function testSolistWirdDreifachAbgerechnet(): void
    {
        $spiel = $this->spielMitAllenGesund(beideKreuzDamenBei: 1);
        static::getContainer()->get(SpielTypResolver::class)->aufloesen($spiel);

        static::getContainer()->get(SpielAbschlussService::class)->abschliessen($spiel);
        $this->em->refresh($spiel);

        $spielwert = $spiel->getWertungDetails()['spielwert'];
        self::assertSame($spielwert * 3, abs($spiel->getTeilnehmerBySitzplatz(1)?->getPunkteDelta()));
        foreach ([2, 3, 4] as $sitz) {
            self::assertSame($spielwert, abs($spiel->getTeilnehmerBySitzplatz($sitz)?->getPunkteDelta()));
        }

        // Sonderpunkte entfallen (TSR 7.2.3/7.2.4).
        foreach (array_column($spiel->getWertungDetails()['positionen'], 'label') as $label) {
            self::assertStringNotContainsString('Fuchs', $label);
            self::assertStringNotContainsString('Doppelkopf', $label);
            self::assertStringNotContainsString('Karlchen', $label);
            self::assertStringNotContainsString('Gegen die Alten', $label);
        }
    }

    /**
     * Spiel in der Vorbehaltsrunde, alle vier haben „gesund" gemeldet.
     * $beideKreuzDamenBei = Sitzplatz, der beide Kreuz-Damen hält (null = je eine
     * bei Sitzplatz 1 und 2).
     */
    private function spielMitAllenGesund(?int $beideKreuzDamenBei): Spiel
    {
        $host  = $this->neuerUser('sh_host_' . ($beideKreuzDamenBei ?? 0));
        $tisch = $this->beitritt->erstelleTisch($host, 'Stille Hochzeit', ZugangsModusTyp::OFFEN);
        [$tisch, $host] = $this->frischLaden($tisch->getId(), $host->getId());

        $spiel = new Spiel();
        $spiel->setTisch($tisch);
        $spiel->setStatus(SpielStatus::VORBEHALT);
        $spiel->setRegelEinstellungenSnapshot($tisch->getRegelEinstellungen());
        $this->em->persist($spiel);

        foreach ($this->blaetter($beideKreuzDamenBei) as $sitz => $karten) {
            $teilnehmer = new SpielTeilnehmer();
            $teilnehmer->setSpiel($spiel);
            $teilnehmer->setSitzplatz($sitz);
            $teilnehmer->setStartkartenIds($karten);
            $teilnehmer->setIstBot(true);
            $teilnehmer->setBotName('Bot ' . $sitz);
            $teilnehmer->setVorbehaltDeklariert(true);
            $teilnehmer->setVorbehaltTyp(VorbehaltTyp::GESUND);

            $this->em->persist($teilnehmer);
            $spiel->getTeilnehmer()->add($teilnehmer);
        }

        $this->em->flush();

        return $spiel;
    }

    /** @return array<int, list<string>> */
    private function blaetter(?int $beideKreuzDamenBei): array
    {
        $vergeben = $beideKreuzDamenBei !== null
            ? [$beideKreuzDamenBei => ['KREUZ_DAME_1', 'KREUZ_DAME_2']]
            : [1 => ['KREUZ_DAME_1'], 2 => ['KREUZ_DAME_2']];

        $blaetter = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($vergeben as $sitz => $karten) {
            $blaetter[$sitz] = $karten;
        }

        $alle = array_map(
            static fn($karte): string => $karte->id(),
            Kartenstapel::komplett()->alleKarten(),
        );
        $rest = array_values(array_diff($alle, array_merge(...array_values($vergeben))));

        foreach ([1, 2, 3, 4] as $sitz) {
            while (count($blaetter[$sitz]) < 12) {
                $blaetter[$sitz][] = array_shift($rest);
            }
        }

        return $blaetter;
    }
}
