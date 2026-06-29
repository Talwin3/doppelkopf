<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\ChatService;
use App\Application\Doppelkopf\TischProtokollService;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\ChatNachrichtTyp;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

final class TischProtokollServiceTest extends DoppelkopfIntegrationTestCase
{
    private function protokoll(): TischProtokollService
    {
        return static::getContainer()->get(TischProtokollService::class);
    }

    private function chat(): ChatService
    {
        return static::getContainer()->get(ChatService::class);
    }

    public function testEreignisErzeugtSystemNachrichtImVerlauf(): void
    {
        $host  = $this->neuerUser('proto_host');
        $tisch = $this->beitritt->erstelleTisch($host, 'Proto-Tisch', ZugangsModusTyp::OFFEN);

        $this->protokoll()->ereignis($tisch, '  Anna ist gesund.  ');

        $verlauf = $this->chat()->verlauf($tisch);
        self::assertCount(1, $verlauf);

        $nachricht = $verlauf[0];
        self::assertTrue($nachricht->istSystem());
        self::assertSame(ChatNachrichtTyp::SYSTEM, $nachricht->getTyp());
        self::assertSame('Anna ist gesund.', $nachricht->getText(), 'Text wird getrimmt');
        self::assertSame(TischProtokollService::ABSENDER, $nachricht->getAbsenderName());
        self::assertNull($nachricht->getAbsender(), 'System-Events haben keinen Absender-User');
    }

    public function testLeeresEreignisWirdIgnoriert(): void
    {
        $host  = $this->neuerUser('proto_leer');
        $tisch = $this->beitritt->erstelleTisch($host, 'Proto-Tisch-2', ZugangsModusTyp::OFFEN);

        $this->protokoll()->ereignis($tisch, '   ');

        self::assertCount(0, $this->chat()->verlauf($tisch));
    }

    public function testZuLangesEreignisWirdGekuerzt(): void
    {
        $host  = $this->neuerUser('proto_lang');
        $tisch = $this->beitritt->erstelleTisch($host, 'Proto-Tisch-3', ZugangsModusTyp::OFFEN);

        $this->protokoll()->ereignis($tisch, str_repeat('x', ChatService::MAX_LAENGE + 50));

        $verlauf = $this->chat()->verlauf($tisch);
        self::assertCount(1, $verlauf);
        self::assertSame(ChatService::MAX_LAENGE, mb_strlen($verlauf[0]->getText()));
    }

    public function testTischVerlassenWirdProtokolliert(): void
    {
        $host    = $this->neuerUser('proto_bleibt');
        $zweiter = $this->neuerUser('proto_geht');
        $tisch   = $this->beitritt->erstelleTisch($host, 'Proto-Tisch-4', ZugangsModusTyp::OFFEN);
        $this->beitritt->beitreten($tisch, $zweiter);

        // Frisch laden, damit die Spieler-Collection des Tisches befüllt ist
        // (wie in einem echten Request) — sonst sieht hatMenschAmTisch() den Host nicht.
        [$tisch, $zweiter] = $this->frischLaden($tisch->getId(), $zweiter->getId());

        // Kein laufendes Spiel → direktes Verlassen erlaubt; Host bleibt am Tisch.
        $this->beitritt->verlassen($tisch, $zweiter);

        $verlauf = $this->chat()->verlauf($tisch);
        $systemTexte = array_map(
            static fn($n) => $n->getText(),
            array_filter($verlauf, static fn($n) => $n->istSystem()),
        );

        self::assertContains('proto_geht verlässt den Tisch.', $systemTexte);
    }

    public function testBotUebernimmtNurEinmalProtokolliert(): void
    {
        [$tisch, $teilnehmer] = $this->teilnehmerAnTisch('proto_uebernahme');

        $this->protokoll()->botUebernimmt($teilnehmer);
        $this->protokoll()->botUebernimmt($teilnehmer); // zweiter Aufruf = No-op

        self::assertTrue($teilnehmer->isVonBotVertreten());

        $texte = $this->systemTexte($tisch);
        self::assertSame(
            ['proto_uebernahme reagiert nicht – ein Bot übernimmt.'],
            $texte,
            'Übernahme wird genau einmal protokolliert',
        );
    }

    public function testSpielerZurueckBeendetVertretung(): void
    {
        [$tisch, $teilnehmer] = $this->teilnehmerAnTisch('proto_zurueck');

        $this->protokoll()->botUebernimmt($teilnehmer);
        $this->protokoll()->spielerZurueck($teilnehmer);
        $this->protokoll()->spielerZurueck($teilnehmer); // zweiter Aufruf = No-op

        self::assertFalse($teilnehmer->isVonBotVertreten());

        self::assertSame(
            [
                'proto_zurueck reagiert nicht – ein Bot übernimmt.',
                'proto_zurueck ist zurück und spielt selbst weiter.',
            ],
            $this->systemTexte($tisch),
        );
    }

    public function testSpielerZurueckOhneVertretungIstNoop(): void
    {
        [$tisch, $teilnehmer] = $this->teilnehmerAnTisch('proto_noop');

        $this->protokoll()->spielerZurueck($teilnehmer);

        self::assertFalse($teilnehmer->isVonBotVertreten());
        self::assertCount(0, $this->chat()->verlauf($tisch));
    }

    public function testBeitrittWirdProtokolliert(): void
    {
        $host    = $this->neuerUser('proto_owner2');
        $zweiter = $this->neuerUser('proto_join');
        $tisch   = $this->beitritt->erstelleTisch($host, 'Beitritt-Tisch', ZugangsModusTyp::OFFEN);

        $this->beitritt->beitreten($tisch, $zweiter);

        self::assertContains('proto_join setzt sich an den Tisch.', $this->systemTexte($tisch));
    }

    public function testRegelwerkAenderungWirdProtokolliert(): void
    {
        $host  = $this->neuerUser('proto_regeln');
        $tisch = $this->beitritt->erstelleTisch($host, 'Regel-Tisch', ZugangsModusTyp::OFFEN);

        $regelwerk = $tisch->getRegelEinstellungen();
        $regelwerk['ohne_neuner'] = true;
        $this->beitritt->regelwerkAktualisieren($tisch, $regelwerk, $host);

        self::assertContains('proto_regeln hat das Regelwerk geändert.', $this->systemTexte($tisch));
    }

    public function testBotsAuffuellenWirdProtokolliert(): void
    {
        $host  = $this->neuerUser('proto_bots');
        $tisch = $this->beitritt->erstelleTisch($host, 'Bots-Tisch', ZugangsModusTyp::OFFEN);

        // Frisch laden, damit getAktiveSpieler() den Host (Platz 1) kennt.
        [$tisch, $host] = $this->frischLaden($tisch->getId(), $host->getId());
        $anzahl = $this->beitritt->botsAuffuellen($tisch, $host);

        self::assertSame(3, $anzahl);
        self::assertContains('3 Bots wurden hinzugefügt.', $this->systemTexte($tisch));
    }

    /**
     * @return array{0: Tisch, 1: SpielTeilnehmer}
     */
    private function teilnehmerAnTisch(string $name): array
    {
        $host  = $this->neuerUser($name);
        $tisch = $this->beitritt->erstelleTisch($host, 'Tisch-' . $name, ZugangsModusTyp::OFFEN);
        $spiel = $this->laufendesSpiel($tisch);

        $teilnehmer = new SpielTeilnehmer();
        $teilnehmer->setSpiel($spiel);
        $teilnehmer->setUser($host);
        $teilnehmer->setSitzplatz(1);
        $this->em->persist($teilnehmer);
        $this->em->flush();

        return [$tisch, $teilnehmer];
    }

    /** @return list<string> Texte aller System-Nachrichten am Tisch, chronologisch. */
    private function systemTexte(Tisch $tisch): array
    {
        return array_values(array_map(
            static fn($n) => $n->getText(),
            array_filter($this->chat()->verlauf($tisch), static fn($n) => $n->istSystem()),
        ));
    }
}
