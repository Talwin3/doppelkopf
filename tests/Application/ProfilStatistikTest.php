<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Doppelkopf\BestenlistenZeitraum;
use App\Application\ProfilStatistikService;
use App\Application\SystemEinstellungService;
use App\Entity\Spiel;
use App\Entity\SpielAnsage;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\AnsageTyp;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\VorbehaltTyp;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Gesamtkonto Stufe 4: Profilstatistik je Kategorie und Zeitraum.
 */
final class ProfilStatistikTest extends DoppelkopfIntegrationTestCase
{
    private const JETZT = '2026-11-23 12:00:00';

    private User $anna;
    private Tisch $tisch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna  = $this->neuerUser('ps_anna');
        $this->tisch = $this->beitritt->erstelleTisch($this->anna, 'Statistik', ZugangsModusTyp::OFFEN);
    }

    public function testGrundzahlenUndParteien(): void
    {
        $this->spiel(Team::RE, true, 3);
        $this->spiel(Team::RE, false, -2);
        $this->spiel(Team::KONTRA, true, 4);
        // Nach abgelehnter Armut neu gegeben: zählt nirgends.
        $this->spiel(Team::RE, true, 99, mitAbrechnung: false);

        $s = $this->statistik();

        self::assertSame(3, $s['grund']['spiele']);
        self::assertSame(2, $s['grund']['siege']);
        self::assertSame(5, $s['grund']['punkte']);
        self::assertSame(66.7, $s['grund']['siegquote']);
        self::assertSame(1.67, $s['grund']['schnitt']);

        self::assertSame(2, $s['parteien']['RE']['spiele']);
        self::assertSame(66.7, $s['parteien']['RE']['anteil']);
        self::assertSame(50.0, $s['parteien']['RE']['siegquote']);
        self::assertSame(1, $s['parteien']['KONTRA']['spiele']);
        self::assertSame(4.0, $s['parteien']['KONTRA']['schnitt']);
    }

    public function testSoliHochzeitUndArmut(): void
    {
        $this->spiel(Team::RE, true, 9, variante: SpielVariante::SOLO_DAMEN);
        $this->spiel(Team::RE, false, -6, variante: SpielVariante::SOLO_DAMEN);
        $this->spiel(Team::RE, true, 12, variante: SpielVariante::SOLO_FLEISCHLOS);
        // Gegen ein fremdes Solo (Kontra) ist es kein eigenes Solo.
        $this->spiel(Team::KONTRA, true, 1, variante: SpielVariante::SOLO_KARO);
        $this->spiel(Team::RE, true, 2, variante: SpielVariante::HOCHZEIT, vorbehalt: VorbehaltTyp::HOCHZEIT);
        $this->spiel(Team::RE, false, -9, stilleHochzeit: true);
        $this->spiel(Team::RE, false, -1, armutSitz: 1);
        $this->spiel(Team::RE, true, 1, armutSitz: 2, annehmerSitz: 1);

        $s = $this->statistik();

        self::assertSame(3, $s['soli']['spiele']);
        self::assertSame(2, $s['soli']['siege']);
        self::assertSame(37.5, $s['soli']['anteil']);
        self::assertSame(
            [['label' => 'Damen-Solo', 'spiele' => 2, 'siege' => 1, 'punkte' => 3], ['label' => 'Fleischlos-Solo', 'spiele' => 1, 'siege' => 1, 'punkte' => 12]],
            $s['soli']['arten'],
        );

        $sonder = array_column($s['sonder'], null, 'label');
        self::assertSame(['label' => 'Hochzeit', 'spiele' => 1, 'siege' => 1], $sonder['Hochzeit']);
        self::assertSame(['label' => 'Stille Hochzeit', 'spiele' => 1, 'siege' => 0], $sonder['Stille Hochzeit']);
        self::assertSame(['label' => 'Armut (eigene)', 'spiele' => 1, 'siege' => 0], $sonder['Armut (eigene)']);
        self::assertSame(['label' => 'Armut übernommen', 'spiele' => 1, 'siege' => 1], $sonder['Armut übernommen']);
    }

    public function testAnsagenGehaltenWennDieEigeneParteiGewinnt(): void
    {
        $this->spiel(Team::RE, true, 4, ansagen: [AnsageTyp::RE, AnsageTyp::KEINE_NEUN]);
        $this->spiel(Team::RE, false, -4, ansagen: [AnsageTyp::RE]);
        $this->spiel(Team::KONTRA, true, 2, ansagen: [AnsageTyp::CONTRA]);
        // Ansage eines Mitspielers zählt nicht für anna.
        $this->spiel(Team::RE, true, 2, ansagen: [AnsageTyp::RE], ansageSitz: 2);

        $ansagen = array_column($this->statistik()['ansagen'], null, 'label');

        self::assertSame(2, $ansagen['Re']['anzahl']);
        self::assertSame(1, $ansagen['Re']['gehalten']);
        self::assertSame(50.0, $ansagen['Re']['quote']);
        self::assertSame(1, $ansagen['Contra']['gehalten']);
        self::assertSame(1, $ansagen['keine 90']['anzahl']);
        self::assertSame(0, $ansagen['schwarz']['anzahl']);
    }

    public function testFilterKategorieGewertetUndZeitraum(): void
    {
        $this->spiel(Team::RE, true, 1, kategorie: WertungsKategorie::DDV, global: true, am: '2026-11-10 18:00');
        $this->spiel(Team::RE, true, 2, kategorie: WertungsKategorie::DDV, global: false, am: '2026-11-10 18:00');
        $this->spiel(Team::RE, true, 4, kategorie: WertungsKategorie::OHNE_NEUNER, global: true, am: '2026-11-10 18:00');
        $this->spiel(Team::RE, true, 8, kategorie: WertungsKategorie::DDV, global: true, am: '2025-06-01 18:00');

        self::assertSame(15, $this->statistik()['grund']['punkte']);
        self::assertSame(11, $this->statistik(WertungsKategorie::DDV)['grund']['punkte']);
        self::assertSame(9, $this->statistik(WertungsKategorie::DDV, nurGewertet: true)['grund']['punkte']);
        self::assertSame(7, $this->statistik(zeitraum: BestenlistenZeitraum::monat(2026, 11))['grund']['punkte']);
        self::assertSame(8, $this->statistik(zeitraum: BestenlistenZeitraum::jahr(2025))['grund']['punkte']);
    }

    public function testVerlaufRechnetInDeutscherZeit(): void
    {
        // 31.10. 23:30 UTC ist in Deutschland schon der 1. November.
        $this->spiel(Team::RE, true, 5, am: '2026-10-31 23:30');
        $this->spiel(Team::RE, true, 2, am: '2026-10-15 12:00');

        $verlauf = $this->statistik()['verlauf'];
        $monate  = array_column($verlauf['monate'], null, 'schluessel');

        self::assertCount(12, $verlauf['monate']);
        self::assertSame('2025-12', $verlauf['monate'][0]['schluessel']);
        self::assertSame('2026-11', $verlauf['monate'][11]['schluessel']);
        self::assertSame(5, $monate['2026-11']['punkte']);
        self::assertSame(2, $monate['2026-10']['punkte']);

        // Jahresansicht: Januar bis Dezember; Monatsansicht markiert den Monat.
        $jahr = $this->statistik(zeitraum: BestenlistenZeitraum::jahr(2026))['verlauf'];
        self::assertSame('Punkteverlauf 2026', $jahr['titel']);
        self::assertSame(['2026-01', '2026-12'], [$jahr['monate'][0]['schluessel'], $jahr['monate'][11]['schluessel']]);
        $monat = $this->statistik(zeitraum: BestenlistenZeitraum::monat(2026, 3))['verlauf'];
        self::assertSame(['2026-03'], array_values(array_column(array_filter($monat['monate'], static fn($m) => $m['markiert']), 'schluessel')));
        self::assertSame('2026-03', $monat['monate'][11]['schluessel']);
    }

    public function testFruehestesSpielFolgtDerAuswahl(): void
    {
        $this->spiel(Team::RE, true, 1, kategorie: WertungsKategorie::SONDERREGELN, global: false, am: '2025-02-01 12:00');
        $this->spiel(Team::RE, true, 1, kategorie: WertungsKategorie::DDV, global: true, am: '2026-06-01 12:00');
        $repo = static::getContainer()->get(\App\Repository\SpielTeilnehmerRepository::class);

        self::assertSame('2025-02-01', $repo->findeFruehestesSpielende($this->anna)?->format('Y-m-d'));
        self::assertSame('2026-06-01', $repo->findeFruehestesSpielende($this->anna, WertungsKategorie::DDV)?->format('Y-m-d'));
        self::assertNull($repo->findeFruehestesSpielende($this->anna, WertungsKategorie::SONDERREGELN, true));
    }

    public function testListenplaetzeNurWennGelistet(): void
    {
        static::getContainer()->get(SystemEinstellungService::class)->set('bestenliste_min_spiele_monat', '1');
        $this->spiel(Team::RE, true, 3, kategorie: WertungsKategorie::DDV, global: true, am: '2026-11-10 18:00');

        $service = static::getContainer()->get(ProfilStatistikService::class);
        $jetzt   = new \DateTimeImmutable(self::JETZT, new \DateTimeZone('UTC'));

        // Gesamt: laufender Monat und laufendes Jahr; Ohne Neuner (nie gespielt) fehlt.
        $gesamt = $service->listenplaetze($this->anna, [WertungsKategorie::DDV, WertungsKategorie::OHNE_NEUNER], null, $jetzt);
        self::assertCount(1, $gesamt);
        self::assertSame(['November 2026', '2026'], array_column($gesamt[0]['plaetze'], 'label'));
        self::assertSame(1, $gesamt[0]['plaetze'][0]['platz']);
        // Einzeln gewählt erscheint die Kategorie trotzdem, mit 0 Spielen.
        $nurOhne = $service->listenplaetze($this->anna, [WertungsKategorie::OHNE_NEUNER], null, $jetzt);
        self::assertSame(0, $nurOhne[0]['plaetze'][0]['spiele']);

        // Gewählter Monat: dieser Monat und sein Jahr; dort fehlen Spiele.
        $oktober = $service->listenplaetze($this->anna, [WertungsKategorie::DDV], BestenlistenZeitraum::monat(2026, 10), $jetzt);
        self::assertSame(['Oktober 2026', '2026'], array_column($oktober[0]['plaetze'], 'label'));
        self::assertNull($oktober[0]['plaetze'][0]['platz']);
        self::assertSame(0, $oktober[0]['plaetze'][0]['spiele']);
        self::assertSame(1, $oktober[0]['plaetze'][0]['minSpiele']);

        // Gewähltes Jahr: nur das Jahr.
        $jahr = $service->listenplaetze($this->anna, [WertungsKategorie::DDV], BestenlistenZeitraum::jahr(2025), $jetzt);
        self::assertSame(['2025'], array_column($jahr[0]['plaetze'], 'label'));

        $this->anna->setInBestenlisten(false);
        self::assertSame([], $service->listenplaetze($this->anna, WertungsKategorie::cases(), null, $jetzt));
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function statistik(
        ?WertungsKategorie $kategorie = null,
        ?BestenlistenZeitraum $zeitraum = null,
        bool $nurGewertet = false,
    ): array {
        return static::getContainer()->get(ProfilStatistikService::class)->fuerUser(
            $this->anna,
            $kategorie,
            $zeitraum,
            $nurGewertet,
            new \DateTimeImmutable(self::JETZT, new \DateTimeZone('UTC')),
        );
    }

    /**
     * Ein beendetes Spiel, in dem anna auf Sitz 1 sitzt.
     *
     * @param list<AnsageTyp> $ansagen
     */
    private function spiel(
        Team $team,
        bool $gewonnen,
        int $punkte,
        SpielVariante $variante = SpielVariante::NORMALSPIEL,
        ?VorbehaltTyp $vorbehalt = null,
        bool $stilleHochzeit = false,
        ?int $armutSitz = null,
        ?int $annehmerSitz = null,
        array $ansagen = [],
        int $ansageSitz = 1,
        WertungsKategorie $kategorie = WertungsKategorie::DDV,
        bool $global = false,
        string $am = '2026-11-10 18:00',
        bool $mitAbrechnung = true,
    ): void {
        $spiel = (new Spiel())
            ->setTisch($this->tisch)
            ->setStatus(SpielStatus::BEENDET)
            ->setVariante($variante)
            ->setHochzeitAlsSolo($stilleHochzeit)
            ->setArmutSpielerSitzplatz($armutSitz)
            ->setArmutAnnehmerSitzplatz($annehmerSitz)
            ->setKategorie($kategorie)
            ->setGlobalGewertet($global)
            ->setBeendetAm(new \DateTimeImmutable($am, new \DateTimeZone('UTC')))
            ->setWertungDetails($mitAbrechnung ? ['spielwert' => abs($punkte)] : null);
        $this->em->persist($spiel);

        $this->em->persist((new SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz(1)->setUser($this->anna)
            ->setTeam($team)->setGewonnen($gewonnen)->setPunkteDelta($punkte)->setVorbehaltTyp($vorbehalt));

        foreach ($ansagen as $typ) {
            $this->em->persist((new SpielAnsage())->setSpiel($spiel)->setSitzplatz($ansageSitz)->setAnsageTyp($typ)
                ->setStichNrBeiAnsage(1)->setKartenNochInHand(12));
        }
        $this->em->flush();
    }
}
