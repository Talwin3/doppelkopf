<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\AnwesenheitService;
use App\Application\Doppelkopf\BotZugService;
use App\Application\Doppelkopf\ChatService;
use App\Application\Doppelkopf\SpielAbschlussService;
use App\Application\Doppelkopf\SpielStartService;
use App\Application\Doppelkopf\TischProtokollService;
use App\Entity\ChatNachricht;
use App\Entity\Spiel;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\BotStaerke;
use App\Enum\SpielStatus;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use App\Repository\SpielTeilnehmerRepository;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Gesamtkonto, Stufe 1: Beim Spielstart werden Kategorie, globale Wertung und
 * Bot-Kennung festgehalten; wer beim Spielende noch vertreten wird, gibt seinen
 * Platz an einen PROFI-Bot ab.
 */
final class GesamtkontoWertungTest extends DoppelkopfIntegrationTestCase
{
    // ── Besetzungsregel (ohne DB) ────────────────────────────────────────────

    public function testVierMenschenZaehlenGlobal(): void
    {
        self::assertTrue(SpielStartService::zaehltGlobal([
            $this->mensch(), $this->mensch(), $this->mensch(), $this->mensch(),
        ]));
    }

    public function testDreiMenschenUndEinProfiZaehlenGlobal(): void
    {
        self::assertTrue(SpielStartService::zaehltGlobal([
            $this->mensch(), $this->mensch(), $this->mensch(), $this->bot(BotStaerke::PROFI),
        ]));
    }

    public function testSchwaechererBotVerhindertGlobaleWertung(): void
    {
        self::assertFalse(SpielStartService::zaehltGlobal([
            $this->mensch(), $this->mensch(), $this->mensch(), $this->bot(BotStaerke::FORTGESCHRITTEN),
        ]));
    }

    public function testZweiMenschenZaehlenNichtGlobal(): void
    {
        self::assertFalse(SpielStartService::zaehltGlobal([
            $this->mensch(), $this->mensch(), $this->bot(BotStaerke::PROFI), $this->bot(BotStaerke::PROFI),
        ]));
    }

    // ── Spielstart ──────────────────────────────────────────────────────────

    public function testSpielstartHaeltKategorieWertungUndBotKennungFest(): void
    {
        [$tisch, $host] = $this->tischMitMenschen(['gk_a', 'gk_b', 'gk_c']);
        $this->beitritt->botsAuffuellen($tisch, $host, BotStaerke::FORTGESCHRITTEN);
        [$tisch] = $this->frischLaden($tisch->getId(), $host->getId());

        $spiel = static::getContainer()->get(SpielStartService::class)->starten($tisch);

        self::assertSame(WertungsKategorie::DDV, $spiel->getKategorie());
        self::assertFalse($spiel->isGlobalGewertet(), 'FORTGESCHRITTEN-Bot am Tisch → nicht global');

        $botPlatz = null;
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->isIstBot()) {
                $botPlatz = $ts;
            }
        }
        self::assertInstanceOf(TischSpieler::class, $botPlatz);

        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->isIstBot()) {
                self::assertTrue($botPlatz->getId()->equals($t->getBotKennung()));
            } else {
                self::assertNull($t->getBotKennung());
            }
        }
    }

    public function testSpielstartOhneNeunerBuchtAufOhneNeuner(): void
    {
        [$tisch] = $this->tischMitMenschen(['gk_n1', 'gk_n2', 'gk_n3', 'gk_n4']);
        $tisch->setRegelEinstellungen(array_replace($tisch->getRegelEinstellungen(), ['ohne_neuner' => true]));
        $this->em->flush();

        $spiel = static::getContainer()->get(SpielStartService::class)->starten($tisch);

        self::assertSame(WertungsKategorie::OHNE_NEUNER, $spiel->getKategorie());
        self::assertTrue($spiel->isGlobalGewertet());
    }

    // ── Abwesende beim Spielende ────────────────────────────────────────────

    public function testWerBeimSpielendeVertretenWirdGibtDenPlatzAnEinenProfiBotAb(): void
    {
        [$tisch, $host] = $this->tischMitMenschen(['gk_h', 'gk_weg', 'gk_x', 'gk_y']);
        $weg = $this->em->getRepository(User::class)->findOneBy(['username' => 'gk_weg']);
        self::assertInstanceOf(User::class, $weg);
        $wegId = $weg->getId();

        $spiel = static::getContainer()->get(SpielStartService::class)->starten($tisch);
        $spielId = $spiel->getId();

        $wegPlatz = static::getContainer()->get(SpielTeilnehmerRepository::class)
            ->findBySpielAndUser($spiel, $weg)?->getSitzplatz();
        self::assertNotNull($wegPlatz);

        // gk_weg reagiert das ganze Spiel über nicht: Der Worker übernimmt jeden seiner
        // Züge nach einem Zug-Timeout und zählt ihn als verpasst.
        $this->spielDurchspielen($spiel, $wegPlatz);

        $this->em->clear();
        $tisch = $this->tischRepo->find($tisch->getId());
        self::assertInstanceOf(Tisch::class, $tisch);
        $gespeichert = $this->em->find(Spiel::class, $spielId);
        self::assertInstanceOf(Spiel::class, $gespeichert);

        // Das beendete Spiel zählt noch für den Abwesenden.
        foreach ($gespeichert->getTeilnehmer() as $t) {
            if ($t->getUser()?->getId()->equals($wegId)) {
                self::assertNotNull($t->getPunkteDelta());
            }
        }
        self::assertTrue($gespeichert->isGlobalGewertet());

        // Der Abwesende sitzt nicht mehr am Tisch, sein Platz gehört einem PROFI-Bot.
        $wegUser = $this->em->find(User::class, $wegId);
        self::assertNull($this->tischSpielerRepo->findByTischAndUser($tisch, $wegUser));

        $neuerPlatz = null;
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->getSitzplatz() !== null && $ts->isIstBot()) {
                $neuerPlatz = $ts;
            }
        }
        self::assertInstanceOf(TischSpieler::class, $neuerPlatz);
        self::assertSame(BotStaerke::PROFI, $neuerPlatz->getBotStaerke());
        self::assertSame(4, $tisch->anzahlAktiveSpieler());

        $meldungen = array_map(
            fn(ChatNachricht $n) => $n->getText(),
            $this->em->getRepository(ChatNachricht::class)->findBy(['tisch' => $tisch]),
        );
        self::assertNotEmpty(array_filter(
            $meldungen,
            fn(string $m) => str_contains($m, 'gk_weg ist nicht zurückgekehrt'),
        ));
    }

    public function testEinVerpassterLetzterZugKostetDenPlatzNicht(): void
    {
        [$tisch] = $this->tischMitMenschen(['gk_k1', 'gk_kurz', 'gk_k3', 'gk_k4']);
        $kurz = $this->em->getRepository(User::class)->findOneBy(['username' => 'gk_kurz']);
        self::assertInstanceOf(User::class, $kurz);
        $kurzId = $kurz->getId();

        $spiel = static::getContainer()->get(SpielStartService::class)->starten($tisch);
        $kurzPlatz = static::getContainer()->get(SpielTeilnehmerRepository::class)
            ->findBySpielAndUser($spiel, $kurz)?->getSitzplatz();
        self::assertNotNull($kurzPlatz);

        // Nur die letzte Karte verpasst: kurz abgelenkt, aber noch da.
        $spielId = $spiel->getId();
        $this->spielDurchspielen($spiel, $kurzPlatz, nurLetzterZug: true);

        $this->em->clear();
        $gespeichert = $this->em->find(Spiel::class, $spielId);
        self::assertInstanceOf(Spiel::class, $gespeichert);
        $teilnehmer = $gespeichert->getTeilnehmerBySitzplatz($kurzPlatz);
        self::assertNotNull($teilnehmer);
        self::assertTrue($teilnehmer->isVonBotVertreten(), 'Der Fall muss tatsächlich eingetreten sein.');
        self::assertSame(1, $teilnehmer->getVerpassteZuegeInFolge());

        $tisch = $this->tischRepo->find($tisch->getId());
        self::assertInstanceOf(Tisch::class, $tisch);
        self::assertNotNull(
            $this->tischSpielerRepo->findByTischAndUser($tisch, $this->em->find(User::class, $kurzId)),
            'Ein einzelner verpasster Zug darf den Platz nicht kosten.',
        );
    }

    public function testSelbstHandelnSetztDieVerpasstenZuegeZurueck(): void
    {
        [$tisch, $host] = $this->tischMitMenschen(['gk_z1', 'gk_z2', 'gk_z3', 'gk_z4']);
        $spiel = static::getContainer()->get(SpielStartService::class)->starten($tisch);
        $t = static::getContainer()->get(SpielTeilnehmerRepository::class)->findBySpielAndUser($spiel, $host);
        self::assertNotNull($t);

        $protokoll = static::getContainer()->get(TischProtokollService::class);
        $protokoll->botUebernimmt($t);
        $t->zugVerpasstZaehlen()->zugVerpasstZaehlen();
        self::assertSame(2, $t->getVerpassteZuegeInFolge());

        $protokoll->spielerZurueck($t);
        self::assertSame(0, $t->getVerpassteZuegeInFolge());
        self::assertFalse($t->isVonBotVertreten());
    }

    public function testChatNachrichtBeendetDieVertretung(): void
    {
        [$tisch, $host] = $this->tischMitMenschen(['gk_c1', 'gk_c2', 'gk_c3', 'gk_c4']);
        $spiel = static::getContainer()->get(SpielStartService::class)->starten($tisch);
        $t = static::getContainer()->get(SpielTeilnehmerRepository::class)->findBySpielAndUser($spiel, $host);
        self::assertNotNull($t);

        static::getContainer()->get(TischProtokollService::class)->botUebernimmt($t);
        $t->zugVerpasstZaehlen()->zugVerpasstZaehlen();
        $this->em->flush();

        static::getContainer()->get(ChatService::class)->sendeNachricht($tisch, $host, 'bin wieder da');

        self::assertFalse($t->isVonBotVertreten());
        self::assertSame(0, $t->getVerpassteZuegeInFolge());
    }

    public function testLebenszeichenOhneLaufendesSpielTutNichts(): void
    {
        [$tisch, $host] = $this->tischMitMenschen(['gk_l1']);

        static::getContainer()->get(AnwesenheitService::class)->lebenszeichen($tisch, $host);

        $this->addToAssertionCount(1);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    /**
     * @param list<string> $namen erster Name = Ersteller
     * @return array{0: Tisch, 1: User}
     */
    private function tischMitMenschen(array $namen): array
    {
        $host  = $this->neuerUser($namen[0]);
        $tisch = $this->beitritt->erstelleTisch($host, 'Konto-Tisch', ZugangsModusTyp::OFFEN);
        foreach (array_slice($namen, 1) as $name) {
            $this->beitritt->beitreten($tisch, $this->neuerUser($name));
        }

        return $this->frischLaden($tisch->getId(), $host->getId());
    }

    /**
     * Spielt wie der Worker: der Spieler am Zug handelt per Bot-Logik, fälliger Abschluss läuft.
     * Für $abwesenderSitz verhält er sich wie nach einem Zug-Timeout (Übernahme melden,
     * nach dem Zug als verpasst zählen) – bei jedem Zug oder, mit $nurLetzterZug, nur
     * bei der letzten Karte.
     */
    private function spielDurchspielen(Spiel $spiel, ?int $abwesenderSitz = null, bool $nurLetzterZug = false): void
    {
        $botZug    = static::getContainer()->get(BotZugService::class);
        $abschluss = static::getContainer()->get(SpielAbschlussService::class);
        $protokoll = static::getContainer()->get(TischProtokollService::class);

        for ($i = 0; $i < 400; $i++) {
            $this->em->refresh($spiel);
            if ($spiel->getStatus() === SpielStatus::BEENDET) {
                return;
            }
            if ($spiel->getAbschlussFaelligAm() !== null) {
                $abschluss->abschliessen($spiel);
                continue;
            }
            $sitz = $spiel->getAktuellerSpielerSitzplatz();
            $letzterZug = $spiel->getStatus() === SpielStatus::LAUFEND && $spiel->getAktuellerStichNr() === 12;
            $vertreten  = $sitz === $abwesenderSitz && (!$nurLetzterZug || $letzterZug);
            $t = static::getContainer()->get(SpielTeilnehmerRepository::class)
                ->findOneBy(['spiel' => $spiel, 'sitzplatz' => $sitz]);
            self::assertNotNull($t);
            if ($vertreten) {
                $protokoll->botUebernimmt($t);
            }
            $botZug->spielenFuerSitzplatz($spiel, $sitz);
            if ($vertreten) {
                $t->zugVerpasstZaehlen();
                $this->em->flush();
            }
        }

        self::fail('Spiel wurde nicht beendet.');
    }

    private function mensch(): TischSpieler
    {
        return (new TischSpieler())->setIstBot(false);
    }

    private function bot(BotStaerke $staerke): TischSpieler
    {
        return (new TischSpieler())->setIstBot(true)->setBotStaerke($staerke);
    }
}
