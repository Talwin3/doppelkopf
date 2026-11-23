<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\TischPunkteService;
use App\Application\SystemEinstellungService;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Entity\User;
use App\Enum\BotStaerke;
use App\Enum\SpielStatus;
use App\Enum\TischPunkteAnzeige;
use App\Enum\TischSteuerungsModus;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Gesamtkonto, Stufe 2: DDV-Tisch mit gesperrten Regeln, Kategorie des Tisches und
 * die Punkteanzeige am Tisch (Monatspunkte mit Listenplatz bzw. Tischliste).
 */
final class GesamtkontoTischTest extends DoppelkopfIntegrationTestCase
{
    private const JETZT = '2026-11-20 12:00:00';

    // ── DDV-Tisch ────────────────────────────────────────────────────────────

    public function testDdvTischStartetMitDdvRegeln(): void
    {
        $host  = $this->neuerUser('gt_ddv');
        $tisch = $this->beitritt->erstelleTisch($host, 'DDV-Runde', ZugangsModusTyp::OFFEN, TischSteuerungsModus::ALLE, true);

        self::assertTrue($tisch->isDdvTisch());
        self::assertSame(WertungsKategorie::DDV, $tisch->getWertungsKategorie());
        foreach (WertungsKategorie::DDV_REGELN as $schluessel => $wert) {
            self::assertSame($wert, $tisch->getRegelEinstellung($schluessel), $schluessel);
        }
    }

    public function testAmDdvTischBleibenDieSpielregelnFestNurDieDarstellungAendertSich(): void
    {
        $host  = $this->neuerUser('gt_sperre');
        $tisch = $this->beitritt->erstelleTisch($host, 'DDV-Runde', ZugangsModusTyp::OFFEN, TischSteuerungsModus::ALLE, true);

        // So kommt es an, wenn das Formular die gesperrten Felder nicht mitschickt
        // oder jemand die Anfrage von Hand baut.
        $this->beitritt->regelwerkAktualisieren($tisch, [
            'soli_erlaubt'    => [],
            'schweinchen'     => true,
            'ohne_neuner'     => true,
            'neue_regel'      => true,
            'augen_mouseover' => false,
        ], $host);

        self::assertSame(WertungsKategorie::DDV, $tisch->getWertungsKategorie());
        self::assertFalse($tisch->getRegelEinstellung('augen_mouseover'));
        self::assertNull($tisch->getRegelEinstellung('neue_regel'));
        self::assertCount(7, $tisch->getRegelEinstellung('soli_erlaubt'));
    }

    public function testAmDdvTischOhneAenderungKeinProtokoll(): void
    {
        $host  = $this->neuerUser('gt_still');
        $tisch = $this->beitritt->erstelleTisch($host, 'DDV-Runde', ZugangsModusTyp::OFFEN, TischSteuerungsModus::ALLE, true);
        [$tisch, $host] = $this->frischLaden($tisch->getId(), $host->getId());

        $this->beitritt->regelwerkAktualisieren($tisch, ['schweinchen' => true], $host);
        self::assertSame([], $this->protokollTexte($tisch), 'Gesperrte Regeln abgeschickt: nichts geändert, nichts melden.');

        $this->beitritt->regelwerkAktualisieren($tisch, ['augen_mouseover' => false], $host);
        self::assertSame(['gt_still hat die Anzeige-Einstellungen geändert.'], $this->protokollTexte($tisch));
    }

    public function testSpeichernOhneAenderungAnAltemTischIstKeineAenderung(): void
    {
        $host  = $this->neuerUser('gt_alt');
        $tisch = $this->beitritt->erstelleTisch($host, 'Alter Tisch', ZugangsModusTyp::OFFEN);
        [$tisch, $host] = $this->frischLaden($tisch->getId(), $host->getId());
        self::assertArrayNotHasKey('augen_mouseover', $tisch->getRegelEinstellungen());

        // Das Formular schickt immer alle Felder, auch augen_mouseover mit seinem Standard.
        $this->beitritt->regelwerkAktualisieren($tisch, array_merge($tisch->getRegelEinstellungen(), ['augen_mouseover' => true]), $host);

        self::assertSame([], $this->protokollTexte($tisch));
    }

    public function testAndereReihenfolgeDerSoliIstKeineAenderung(): void
    {
        $host  = $this->neuerUser('gt_soli');
        $tisch = $this->beitritt->erstelleTisch($host, 'Soli-Runde', ZugangsModusTyp::OFFEN);
        [$tisch, $host] = $this->frischLaden($tisch->getId(), $host->getId());

        $regelwerk = $tisch->getRegelEinstellungen();
        $regelwerk['soli_erlaubt'] = array_reverse($regelwerk['soli_erlaubt']);
        $this->beitritt->regelwerkAktualisieren($tisch, $regelwerk, $host);

        self::assertSame([], $this->protokollTexte($tisch));
    }

    public function testDdvSperreGiltAuchBeimDirektenSetzenAmTisch(): void
    {
        $tisch = (new Tisch())->setDdvTisch(true);
        $tisch->setRegelEinstellungen(['schweinchen' => true, 'augen_mouseover' => false]);

        self::assertSame(WertungsKategorie::DDV, $tisch->getWertungsKategorie());
        self::assertFalse($tisch->getRegelEinstellung('schweinchen'));
        self::assertFalse($tisch->getRegelEinstellung('augen_mouseover'));
    }

    public function testNormalerTischWirdDurchAbweichungZuSonderregeln(): void
    {
        $host  = $this->neuerUser('gt_frei');
        $tisch = $this->beitritt->erstelleTisch($host, 'Freie Runde', ZugangsModusTyp::OFFEN);

        self::assertFalse($tisch->isDdvTisch());
        self::assertSame(WertungsKategorie::DDV, $tisch->getWertungsKategorie(), 'Neue Tische sind standardmäßig DDV-konform.');

        $this->beitritt->regelwerkAktualisieren($tisch, array_merge($tisch->getRegelEinstellungen(), ['schweinchen' => true]), $host);
        self::assertSame(WertungsKategorie::SONDERREGELN, $tisch->getWertungsKategorie());

        $this->beitritt->regelwerkAktualisieren($tisch, array_merge($tisch->getRegelEinstellungen(), ['ohne_neuner' => true]), $host);
        self::assertSame(WertungsKategorie::OHNE_NEUNER, $tisch->getWertungsKategorie());
    }

    // ── Zeitraum und Listenplätze (ohne DB) ─────────────────────────────────

    public function testMonatsgrenzenFolgenDerDeutschenZeit(): void
    {
        // 30.11. 23:30 UTC ist in Deutschland schon der 1. Dezember.
        [$von, $bis] = TischPunkteService::monatsGrenzen(new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')));
        self::assertSame('2026-11-30 23:00:00', $von->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 23:00:00', $bis->format('Y-m-d H:i:s'));

        // Sommerzeit: Monatsanfang ist 22:00 UTC am Vortag.
        [$von, $bis] = TischPunkteService::monatsGrenzen(new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')));
        self::assertSame('2026-06-30 22:00:00', $von->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-31 22:00:00', $bis->format('Y-m-d H:i:s'));
    }

    public function testListenplaetzeNachPunktenJeSpielMitGleichstandUndMindestanzahl(): void
    {
        $plaetze = TischPunkteService::listenplaetze([
            ['user_id' => 'a', 'punkte' => 10, 'spiele' => 5, 'oeffentlich' => true],  // 2,0
            ['user_id' => 'b', 'punkte' => 30, 'spiele' => 10, 'oeffentlich' => true], // 3,0
            ['user_id' => 'c', 'punkte' => 6, 'spiele' => 3, 'oeffentlich' => true],   // 2,0
            ['user_id' => 'd', 'punkte' => 99, 'spiele' => 2, 'oeffentlich' => true],  // zu wenig Spiele
            ['user_id' => 'e', 'punkte' => 90, 'spiele' => 9, 'oeffentlich' => false], // nicht öffentlich
            ['user_id' => 'f', 'punkte' => -3, 'spiele' => 3, 'oeffentlich' => true],
        ], 3);

        ksort($plaetze);
        self::assertSame(['a' => 2, 'b' => 1, 'c' => 2, 'f' => 4], $plaetze);
    }

    // ── Punkteanzeige am Tisch ──────────────────────────────────────────────

    public function testMonatspunkteZaehlenNurGewerteteSpieleDerKategorieImLaufendenMonat(): void
    {
        static::getContainer()->get(SystemEinstellungService::class)->set('bestenliste_min_spiele_monat', '2');
        [$tisch, $anna, $bert, $carl] = $this->tischMitDreiMenschenUndProfi();
        $fremd = $this->neuerUser('gt_fremd');

        $nov = new \DateTimeImmutable('2026-11-10 18:00:00');
        // Zwei gewertete DDV-Spiele im November
        $this->buche($tisch, WertungsKategorie::DDV, true, $nov, [[$anna, 3], [$bert, -3], [$fremd, 2]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, $nov, [[$anna, 1], [$bert, 5], [$fremd, 2]]);
        // Zählt nicht: nicht global gewertet, andere Kategorie, Vormonat, nicht beendet
        $this->buche($tisch, WertungsKategorie::DDV, false, $nov, [[$anna, 50]]);
        $this->buche($tisch, WertungsKategorie::SONDERREGELN, true, $nov, [[$anna, 50]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, new \DateTimeImmutable('2026-10-31 22:59:00'), [[$anna, 50]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, $nov, [[$anna, 50]], SpielStatus::LAUFEND);
        // Nach abgelehnter Armut neu gegeben: BEENDET, aber ohne Abrechnung → zählt nicht
        $this->buche($tisch, WertungsKategorie::DDV, true, $nov, [[$anna, 0], [$bert, 0]], mitAbrechnung: false);

        $daten = $this->punkte($tisch);

        self::assertSame(TischPunkteAnzeige::MONAT, $daten['anzeige']);
        self::assertSame(WertungsKategorie::DDV, $daten['kategorie']);
        self::assertSame('November', $daten['zeitraum']);

        $plaetze = $this->nachName($daten['plaetze']);
        // fremd: 4 Punkte/2 Spiele = 2,0 → Platz 1; anna 4/2 = 2,0 → Platz 1; bert 2/2 = 1,0 → Platz 3
        self::assertSame(4, $plaetze['gt_anna']['punkte']);
        self::assertSame(1, $plaetze['gt_anna']['rang']);
        self::assertSame(2, $plaetze['gt_bert']['punkte']);
        self::assertSame(3, $plaetze['gt_bert']['rang']);
        self::assertSame(0, $plaetze['gt_carl']['punkte']);
        self::assertNull($plaetze['gt_carl']['rang'], 'Unter der Mindestanzahl kein Listenplatz.');
        self::assertSame(0, $plaetze['gt_carl']['spiele']);

        $bot = $this->botPlatz($daten['plaetze']);
        self::assertNull($bot['punkte'], 'Bots haben keine Monatspunkte.');
        self::assertNull($bot['rang']);
    }

    public function testOhneMindestanzahlFehltDerRangNurMangelsSpielen(): void
    {
        static::getContainer()->get(SystemEinstellungService::class)->set('bestenliste_min_spiele_monat', '0');
        [$tisch, $anna] = $this->tischMitDreiMenschenUndProfi();
        $this->buche($tisch, WertungsKategorie::DDV, true, new \DateTimeImmutable('2026-11-10 18:00:00'), [[$anna, 2]]);

        $plaetze = $this->nachName($this->punkte($tisch)['plaetze']);

        self::assertSame(1, $plaetze['gt_anna']['rang']);
        self::assertNull($plaetze['gt_anna']['ohneRang']);
        self::assertNull($plaetze['gt_bert']['rang']);
        self::assertSame('spiele', $plaetze['gt_bert']['ohneRang'], 'Kein Spiel heißt nicht „Profil privat“.');
    }

    public function testNichtOeffentlichesProfilZeigtWederRangNochPunkte(): void
    {
        static::getContainer()->get(SystemEinstellungService::class)->set('bestenliste_min_spiele_monat', '1');
        [$tisch, $anna] = $this->tischMitDreiMenschenUndProfi();
        $anna->setProfilOeffentlich(false);
        $this->em->flush();
        $this->buche($tisch, WertungsKategorie::DDV, true, new \DateTimeImmutable('2026-11-10 18:00:00'), [[$anna, 2]]);

        $plaetze = $this->nachName($this->punkte($tisch)['plaetze']);

        self::assertNull($plaetze['gt_anna']['rang']);
        self::assertSame('privat', $plaetze['gt_anna']['ohneRang']);
        self::assertNull($plaetze['gt_anna']['punkte'], 'Bei privatem Profil keine Monatspunkte zeigen.');
    }

    public function testTischlisteZaehltAlleBeendetenSpieleDesTischesAuchFuerBots(): void
    {
        [$tisch, $anna, $bert] = $this->tischMitDreiMenschenUndProfi();
        $tisch->setPunkteAnzeige(TischPunkteAnzeige::TISCH);
        $botTs = $this->botTischSpieler($tisch);

        $zeit = new \DateTimeImmutable('2026-11-10 18:00:00');
        // Nicht gewertet, Sonderregeln, Vormonat: zählt in der Tischliste trotzdem
        $this->buche($tisch, WertungsKategorie::SONDERREGELN, false, new \DateTimeImmutable('2026-09-01'), [[$anna, 3], [$botTs, -3]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, $zeit, [[$anna, 2], [$bert, -2], [$botTs, 4]]);
        // Abgebrochen bzw. laufend: zählt nirgends
        $this->buche($tisch, WertungsKategorie::DDV, true, $zeit, [[$anna, 100], [$botTs, 100]], SpielStatus::LAUFEND);

        // Ein anderer Tisch zählt nicht
        $andererHost = $this->neuerUser('gt_anders');
        $anderer = $this->beitritt->erstelleTisch($andererHost, 'Anderer', ZugangsModusTyp::OFFEN);
        $this->buche($anderer, WertungsKategorie::DDV, true, $zeit, [[$anna, 77]]);

        $plaetze = $this->nachName($this->punkte($tisch)['plaetze']);

        self::assertSame(5, $plaetze['gt_anna']['punkte']);
        self::assertSame(2, $plaetze['gt_anna']['spiele']);
        self::assertSame(-2, $plaetze['gt_bert']['punkte']);
        self::assertSame(0, $plaetze['gt_carl']['punkte']);
        self::assertSame(1, $this->botPlatz($this->punkte($tisch)['plaetze'])['punkte']);
    }

    public function testPunkteAnzeigeUmschalten(): void
    {
        $host  = $this->neuerUser('gt_schalter');
        $tisch = $this->beitritt->erstelleTisch($host, 'Schalter', ZugangsModusTyp::OFFEN);
        self::assertSame(TischPunkteAnzeige::MONAT, $tisch->getPunkteAnzeige());

        $this->beitritt->punkteAnzeigeSetzen($tisch, TischPunkteAnzeige::TISCH);
        $this->em->clear();

        $neu = $this->tischRepo->find($tisch->getId());
        self::assertInstanceOf(Tisch::class, $neu);
        self::assertSame(TischPunkteAnzeige::TISCH, $neu->getPunkteAnzeige());
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function protokollTexte(Tisch $tisch): array
    {
        return array_map(
            static fn(\App\Entity\ChatNachricht $n) => $n->getText(),
            $this->em->getRepository(\App\Entity\ChatNachricht::class)->findBy(['tisch' => $tisch], ['erstelltAm' => 'ASC']),
        );
    }

    /** @return array{0: Tisch, 1: User, 2: User, 3: User} */
    private function tischMitDreiMenschenUndProfi(): array
    {
        $anna  = $this->neuerUser('gt_anna');
        $bert  = $this->neuerUser('gt_bert');
        $carl  = $this->neuerUser('gt_carl');
        $tisch = $this->beitritt->erstelleTisch($anna, 'Konto-Tisch', ZugangsModusTyp::OFFEN);
        $this->beitritt->beitreten($tisch, $bert);
        $this->beitritt->beitreten($tisch, $carl);
        [$tisch, $anna] = $this->frischLaden($tisch->getId(), $anna->getId());
        $this->beitritt->botsAuffuellen($tisch, $anna, BotStaerke::PROFI);

        [$tisch, $anna] = $this->frischLaden($tisch->getId(), $anna->getId());
        $bert = $this->em->find(User::class, $bert->getId());
        $carl = $this->em->find(User::class, $carl->getId());
        self::assertInstanceOf(User::class, $bert);
        self::assertInstanceOf(User::class, $carl);

        return [$tisch, $anna, $bert, $carl];
    }

    private function botTischSpieler(Tisch $tisch): TischSpieler
    {
        foreach ($tisch->getAktiveSpieler() as $ts) {
            if ($ts->isIstBot()) {
                return $ts;
            }
        }
        self::fail('Kein Bot am Tisch.');
    }

    /**
     * Legt ein Spiel mit fertiger Wertung an.
     *
     * @param list<array{0: User|TischSpieler, 1: int}> $buchungen
     */
    private function buche(
        Tisch $tisch,
        WertungsKategorie $kategorie,
        bool $global,
        \DateTimeImmutable $beendetAm,
        array $buchungen,
        SpielStatus $status = SpielStatus::BEENDET,
        bool $mitAbrechnung = true,
    ): void {

        $spiel = (new Spiel())
            ->setTisch($tisch)
            ->setStatus($status)
            ->setKategorie($kategorie)
            ->setGlobalGewertet($global)
            ->setBeendetAm($status === SpielStatus::BEENDET ? $beendetAm : null)
            // Abrechnung wie nach einem echten Spiel; ohne sie gilt es als neu gegeben.
            ->setWertungDetails($status === SpielStatus::BEENDET && $mitAbrechnung ? ['spielwert' => 1] : null);
        $this->em->persist($spiel);

        $sitz = 1;
        foreach ($buchungen as [$wer, $delta]) {
            $t = (new SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz($sitz++)->setPunkteDelta($delta);
            if ($wer instanceof TischSpieler) {
                $t->setIstBot(true)->setBotStaerke(BotStaerke::PROFI)->setBotKennung($wer->getId());
            } else {
                $t->setUser($wer);
            }
            $this->em->persist($t);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function punkte(Tisch $tisch): array
    {
        return static::getContainer()->get(TischPunkteService::class)
            ->fuerTisch($tisch, new \DateTimeImmutable(self::JETZT, new \DateTimeZone('UTC')));
    }

    /**
     * @param list<array<string, mixed>> $plaetze
     * @return array<string, array<string, mixed>>
     */
    private function nachName(array $plaetze): array
    {
        $ergebnis = [];
        foreach ($plaetze as $p) {
            $ergebnis[$p['name']] = $p;
        }

        return $ergebnis;
    }

    /**
     * @param list<array<string, mixed>> $plaetze
     * @return array<string, mixed>
     */
    private function botPlatz(array $plaetze): array
    {
        foreach ($plaetze as $p) {
            if ($p['istBot']) {
                return $p;
            }
        }
        self::fail('Kein Bot in der Punkteanzeige.');
    }
}
