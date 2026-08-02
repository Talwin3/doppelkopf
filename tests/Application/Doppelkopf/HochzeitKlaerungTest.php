<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\KarteAusspielenService;
use App\Application\Doppelkopf\SpielAbschlussService;
use App\Domain\Doppelkopf\ValueObject\Kartenstapel;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Klärungsfrist der Hochzeit: Der Partner wird durch den ersten Stich bestimmt,
 * den ein Mitspieler gewinnt – aber nur in den ersten drei Stichen. Danach bleibt
 * der Hochzeitsspieler allein und das Spiel wird als Solo abgerechnet.
 *
 * Die Blätter sind fest vorgegeben (kein Mischen), damit die Stichfolge
 * deterministisch ist.
 */
final class HochzeitKlaerungTest extends DoppelkopfIntegrationTestCase
{
    private KarteAusspielenService $ausspielen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ausspielen = static::getContainer()->get(KarteAusspielenService::class);
    }

    public function testPartnerWirdImKlaerungsstichBestimmt(): void
    {
        $spiel = $this->hochzeitsSpiel();

        // Sitzplatz 1 spielt eine niedrige Fehlfarbe an, Sitzplatz 2 sticht mit der Dulle.
        $this->stichSpielen($spiel, [
            1 => 'PIK_NEUN_1',
            2 => 'PIK_ASS_1',
            3 => 'PIK_KOENIG_2',
            4 => 'PIK_NEUN_2',
        ]);

        $this->em->refresh($spiel);

        self::assertTrue($spiel->isHochzeitAufgeloest(), 'Hochzeit sollte geklärt sein.');
        self::assertFalse($spiel->isHochzeitAlsSolo(), 'Mit Partner ist es kein Solo.');
        self::assertSame(Team::RE, $spiel->getTeilnehmerBySitzplatz(2)?->getTeam(), 'Stichgewinner wird Partner.');
        self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz(3)?->getTeam());
        self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz(4)?->getTeam());
    }

    public function testPartnerImDrittenStichZaehltNoch(): void
    {
        $spiel = $this->hochzeitsSpiel();

        // Stiche 1 und 2 gewinnt der Hochzeitsspieler mit den beiden Dullen …
        $this->stichSpielen($spiel, [1 => 'HERZ_ZEHN_1', 2 => 'KARO_NEUN_1', 3 => 'KARO_NEUN_2', 4 => 'KARO_KOENIG_2']);
        $this->stichSpielen($spiel, [1 => 'HERZ_ZEHN_2', 2 => 'KARO_KOENIG_1', 3 => 'KARO_ZEHN_1', 4 => 'KARO_ZEHN_2']);

        $this->em->refresh($spiel);
        self::assertFalse($spiel->isHochzeitAufgeloest(), 'Nach zwei eigenen Stichen noch offen.');

        // … im dritten kommt Sitzplatz 3 zum Stich → gerade noch rechtzeitig Partner.
        $this->stichSpielen($spiel, [1 => 'PIK_NEUN_1', 2 => 'PIK_KOENIG_1', 3 => 'PIK_ASS_2', 4 => 'PIK_ZEHN_2']);

        $this->em->refresh($spiel);
        self::assertTrue($spiel->isHochzeitAufgeloest());
        self::assertFalse($spiel->isHochzeitAlsSolo());
        self::assertSame(Team::RE, $spiel->getTeilnehmerBySitzplatz(3)?->getTeam());
    }

    public function testDreiEigeneSticheMachenDieHochzeitZumSolo(): void
    {
        $spiel = $this->hochzeitsSpiel();

        // Der Hochzeitsspieler gewinnt alle drei Klärungsstiche selbst.
        $this->stichSpielen($spiel, [1 => 'HERZ_ZEHN_1', 2 => 'KARO_NEUN_1', 3 => 'KARO_NEUN_2', 4 => 'KARO_KOENIG_2']);
        $this->stichSpielen($spiel, [1 => 'HERZ_ZEHN_2', 2 => 'KARO_KOENIG_1', 3 => 'KARO_ZEHN_1', 4 => 'KARO_ZEHN_2']);
        $this->stichSpielen($spiel, [1 => 'KREUZ_DAME_1', 2 => 'KARO_BUBE_1', 3 => 'KARO_BUBE_2', 4 => 'KARO_ASS_1']);

        $this->em->refresh($spiel);

        self::assertTrue($spiel->isHochzeitAufgeloest(), 'Nach dem dritten Stich steht das Ergebnis fest.');
        self::assertTrue($spiel->isHochzeitAlsSolo(), 'Ungeklärte Hochzeit wird zum Solo.');

        // Teams bleiben: einer gegen drei.
        self::assertSame(Team::RE, $spiel->getTeilnehmerBySitzplatz(1)?->getTeam());
        foreach ([2, 3, 4] as $sitz) {
            self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz($sitz)?->getTeam());
        }
    }

    public function testSpaeterStichMachtKeinenPartnerMehr(): void
    {
        $spiel = $this->hochzeitsSpiel();

        $this->stichSpielen($spiel, [1 => 'HERZ_ZEHN_1', 2 => 'KARO_NEUN_1', 3 => 'KARO_NEUN_2', 4 => 'KARO_KOENIG_2']);
        $this->stichSpielen($spiel, [1 => 'HERZ_ZEHN_2', 2 => 'KARO_KOENIG_1', 3 => 'KARO_ZEHN_1', 4 => 'KARO_ZEHN_2']);
        $this->stichSpielen($spiel, [1 => 'KREUZ_DAME_1', 2 => 'KARO_BUBE_1', 3 => 'KARO_BUBE_2', 4 => 'KARO_ASS_1']);

        // Vierter Stich geht an Sitzplatz 2 – zu spät, die Frist ist abgelaufen.
        $this->stichSpielen($spiel, [1 => 'PIK_NEUN_1', 2 => 'PIK_ASS_1', 3 => 'PIK_ASS_2', 4 => 'PIK_ZEHN_2']);

        $this->em->refresh($spiel);

        self::assertTrue($spiel->isHochzeitAlsSolo());
        self::assertSame(Team::KONTRA, $spiel->getTeilnehmerBySitzplatz(2)?->getTeam(), 'Kein nachträglicher Partner.');
    }

    public function testUngeklaerteHochzeitWirdWieEinSoloAbgerechnet(): void
    {
        $spiel = $this->hochzeitsSpiel();
        $spiel->setHochzeitAufgeloest(true);
        $spiel->setHochzeitAlsSolo(true);
        $this->em->flush();

        static::getContainer()->get(SpielAbschlussService::class)->abschliessen($spiel);
        $this->em->refresh($spiel);

        $solist = $spiel->getTeilnehmerBySitzplatz(1);
        self::assertNotNull($solist);
        self::assertNotNull($solist->getPunkteDelta());

        // Der Solist bekommt den dreifachen Spielwert, die drei Gegner je den einfachen.
        $spielwert = $spiel->getWertungDetails()['spielwert'];
        self::assertSame(
            $spielwert * 3,
            abs($solist->getPunkteDelta()),
            'Solist muss dreifach abgerechnet werden.',
        );
        foreach ([2, 3, 4] as $sitz) {
            self::assertSame($spielwert, abs($spiel->getTeilnehmerBySitzplatz($sitz)?->getPunkteDelta()));
        }

        // Sonderpunkte (Fuchs/Doppelkopf/Karlchen) gibt es im Solo nicht.
        $labels = array_column($spiel->getWertungDetails()['positionen'], 'label');
        foreach ($labels as $label) {
            self::assertStringNotContainsString('Fuchs', $label);
            self::assertStringNotContainsString('Doppelkopf', $label);
            self::assertStringNotContainsString('Karlchen', $label);
            self::assertStringNotContainsString('Gegen die Alten', $label);
        }
    }

    /**
     * Laufendes Hochzeitsspiel mit fest verteilten Blättern: Sitzplatz 1 hält beide
     * Kreuz-Damen sowie beide Dullen und kann die ersten Stiche daher nach Belieben
     * gewinnen oder abgeben.
     */
    private function hochzeitsSpiel(): Spiel
    {
        $host  = $this->neuerUser('hz_host');
        $tisch = $this->beitritt->erstelleTisch($host, 'Hochzeit', ZugangsModusTyp::OFFEN);
        [$tisch, $host] = $this->frischLaden($tisch->getId(), $host->getId());
        \assert($tisch instanceof Tisch);

        $spiel = new Spiel();
        $spiel->setTisch($tisch);
        $spiel->setStatus(SpielStatus::LAUFEND);
        $spiel->setVariante(SpielVariante::HOCHZEIT);
        $spiel->setRegelEinstellungenSnapshot($tisch->getRegelEinstellungen());
        $spiel->setAktuellerStichNr(1);
        $spiel->setAktuellerSpielerSitzplatz(1);
        $this->em->persist($spiel);

        foreach ($this->blaetter() as $sitz => $karten) {
            $teilnehmer = new SpielTeilnehmer();
            $teilnehmer->setSpiel($spiel);
            $teilnehmer->setSitzplatz($sitz);
            $teilnehmer->setStartkartenIds($karten);
            // Vor der Klärung: der Hochzeitsspieler allein als RE.
            $teilnehmer->setTeam($sitz === 1 ? Team::RE : Team::KONTRA);
            $teilnehmer->setIstBot(true);
            $teilnehmer->setBotName('Bot ' . $sitz);
            $teilnehmer->setVorbehaltDeklariert(true);

            $this->em->persist($teilnehmer);
            $spiel->getTeilnehmer()->add($teilnehmer);
        }

        $this->em->flush();

        return $spiel;
    }

    /**
     * Feste Kartenverteilung. Entscheidend sind die hier namentlich vergebenen Karten –
     * sie machen jeden Zug der Testskripte bedienpflicht-konform:
     *  - Sitzplatz 1 hält beide Kreuz-Damen (die Hochzeit), beide Dullen und zwei Pik
     *    zum Anspielen einer Fehlfarbe.
     *  - Die Sitzplätze 2–4 haben je drei niedrige Trümpfe (um Trumpf-Anspiele zu
     *    bedienen) und je zwei Pik (um Pik-Anspiele zu bedienen).
     * Der Rest wird nur aufgefüllt und kommt in den Skripten nicht vor.
     *
     * @return array<int, list<string>>
     */
    private function blaetter(): array
    {
        $vergeben = [
            1 => ['KREUZ_DAME_1', 'KREUZ_DAME_2', 'HERZ_ZEHN_1', 'HERZ_ZEHN_2', 'PIK_NEUN_1', 'PIK_ZEHN_1'],
            2 => ['KARO_NEUN_1', 'KARO_KOENIG_1', 'KARO_BUBE_1', 'PIK_ASS_1', 'PIK_KOENIG_1'],
            3 => ['KARO_NEUN_2', 'KARO_ZEHN_1', 'KARO_BUBE_2', 'PIK_ASS_2', 'PIK_KOENIG_2'],
            4 => ['KARO_KOENIG_2', 'KARO_ZEHN_2', 'KARO_ASS_1', 'PIK_ZEHN_2', 'PIK_NEUN_2'],
        ];

        $alle = array_map(
            static fn($karte): string => $karte->id(),
            Kartenstapel::komplett()->alleKarten(),
        );
        $rest = array_values(array_diff($alle, array_merge(...array_values($vergeben))));

        foreach ([1, 2, 3, 4] as $sitz) {
            while (count($vergeben[$sitz]) < 12) {
                $vergeben[$sitz][] = array_shift($rest);
            }
        }

        return $vergeben;
    }

    /**
     * Spielt einen kompletten Stich in Sitzplatz-Reihenfolge des Anspielers.
     *
     * @param array<int, string> $karten Sitzplatz → Karten-ID
     */
    private function stichSpielen(Spiel $spiel, array $karten): void
    {
        foreach ($karten as $sitzplatz => $karteId) {
            $this->ausspielen->spielenAlsBot($spiel, $sitzplatz, $karteId);
        }
    }
}
