<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf;

use App\Application\Doppelkopf\BestenlisteService;
use App\Application\Doppelkopf\BestenlistenZeitraum;
use App\Application\SystemEinstellungService;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\SpielStatus;
use App\Enum\WertungsKategorie;
use App\Enum\ZugangsModusTyp;
use App\Repository\SpielTeilnehmerRepository;
use App\Tests\Support\DoppelkopfIntegrationTestCase;

/**
 * Gesamtkonto Stufe 3: Monats- und Jahresbestenlisten je Kategorie.
 */
final class BestenlisteTest extends DoppelkopfIntegrationTestCase
{
    private static function utc(string $zeit): \DateTimeImmutable
    {
        return new \DateTimeImmutable($zeit, new \DateTimeZone('UTC'));
    }

    // ── Zeitraum (ohne DB) ──────────────────────────────────────────────────

    public function testJahresgrenzenFolgenDerDeutschenZeit(): void
    {
        // Silvester 23:30 UTC ist in Deutschland schon Neujahr.
        $jahr = BestenlistenZeitraum::aktuell(BestenlistenZeitraum::JAHR, self::utc('2026-12-31 23:30:00'));
        self::assertSame(2027, $jahr->jahr);

        [$von, $bis] = BestenlistenZeitraum::jahr(2026)->grenzen();
        self::assertSame('2025-12-31 23:00:00', $von->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 23:00:00', $bis->format('Y-m-d H:i:s'));
    }

    public function testBlaetternUeberJahresgrenzen(): void
    {
        self::assertSame('Dezember 2025', BestenlistenZeitraum::monat(2026, 1)->vorheriger()->label());
        self::assertSame('Januar 2027', BestenlistenZeitraum::monat(2026, 12)->naechster()->label());
        self::assertSame('2025', BestenlistenZeitraum::jahr(2026)->vorheriger()->label());
    }

    public function testAnfrageMitZukunftOderUnsinnFaelltAufDenLaufendenZeitraum(): void
    {
        $jetzt = self::utc('2026-11-23 12:00:00');

        self::assertSame('November 2026', BestenlistenZeitraum::ausAnfrage('monat', 2027, 3, $jetzt)->label());
        self::assertSame('November 2026', BestenlistenZeitraum::ausAnfrage('monat', 2026, 13, $jetzt)->label());
        self::assertSame('November 2026', BestenlistenZeitraum::ausAnfrage('quatsch', null, null, $jetzt)->label());
        self::assertSame('2026', BestenlistenZeitraum::ausAnfrage('jahr', 2030, null, $jetzt)->label());
        self::assertSame('März 2026', BestenlistenZeitraum::ausAnfrage('monat', 2026, 3, $jetzt)->label());
        self::assertSame('2025', BestenlistenZeitraum::ausAnfrage('jahr', 2025, 7, $jetzt)->label());
    }

    // ── Rangliste (ohne DB) ─────────────────────────────────────────────────

    public function testRanglisteNachPunktenOderSchnittMitGleichstand(): void
    {
        $konten = [
            ['user_id' => 'a', 'punkte' => 20, 'spiele' => 10, 'gelistet' => true], // 2,0
            ['user_id' => 'b', 'punkte' => 12, 'spiele' => 4, 'gelistet' => true],  // 3,0
            ['user_id' => 'c', 'punkte' => 20, 'spiele' => 5, 'gelistet' => true],  // 4,0
            ['user_id' => 'd', 'punkte' => 50, 'spiele' => 2, 'gelistet' => true],  // zu wenig Spiele
            ['user_id' => 'e', 'punkte' => 90, 'spiele' => 9, 'gelistet' => false], // will nicht
        ];

        $nachSchnitt = BestenlisteService::rangliste($konten, 3);
        self::assertSame(['c', 'b', 'a'], array_column($nachSchnitt, 'user_id'));
        self::assertSame([1, 2, 3], array_column($nachSchnitt, 'rang'));

        // Nach Punkten: a und c gleichauf (20) → gleicher Platz, der bessere Schnitt steht vorn.
        $nachPunkten = BestenlisteService::rangliste($konten, 3, BestenlisteService::NACH_PUNKTEN);
        self::assertSame(['c', 'a', 'b'], array_column($nachPunkten, 'user_id'));
        self::assertSame([1, 1, 3], array_column($nachPunkten, 'rang'));

        self::assertSame(1, BestenlisteService::anzahlUnterMindestanzahl($konten, 3), 'Nur d fehlen Spiele; e will nicht.');
    }

    // ── Liste aus der DB ────────────────────────────────────────────────────

    public function testJahreslisteZaehltAlleMonateNurDerKategorieUndNurGewertet(): void
    {
        $einstellungen = static::getContainer()->get(SystemEinstellungService::class);
        $einstellungen->set('bestenliste_min_spiele_jahr', '2');

        $anna  = $this->neuerUser('bl_anna');
        $bert  = $this->neuerUser('bl_bert');
        $carl  = $this->neuerUser('bl_carl');
        $dora  = $this->neuerUser('bl_dora');
        $tisch = $this->beitritt->erstelleTisch($anna, 'Listen-Tisch', ZugangsModusTyp::OFFEN);

        // anna: zwei DDV-Spiele in verschiedenen Monaten → 6 Punkte / 2 Spiele
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2026-02-10 18:00'), [[$anna, 4], [$bert, -1]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2026-09-10 18:00'), [[$anna, 2], [$bert, 3], [$carl, 5]]);
        // Zählt nicht: andere Kategorie, nicht global, Vorjahr, neu gegebene Armut
        $this->buche($tisch, WertungsKategorie::OHNE_NEUNER, true, self::utc('2026-03-01 18:00'), [[$anna, 99]]);
        $this->buche($tisch, WertungsKategorie::DDV, false, self::utc('2026-03-01 18:00'), [[$anna, 99]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2025-12-31 22:30'), [[$anna, 99]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2026-03-01 18:00'), [[$anna, 0], [$carl, 0]], mitAbrechnung: false);
        // dora will nicht in die Liste, ihre Spiele zählen trotzdem für sie
        $dora->setInBestenlisten(false);
        $this->em->flush();
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2026-05-01 18:00'), [[$dora, 10]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2026-05-02 18:00'), [[$dora, 10]]);

        $ansicht = static::getContainer()->get(BestenlisteService::class)
            ->ansicht(WertungsKategorie::DDV, BestenlistenZeitraum::jahr(2026));
        $liste   = $ansicht['liste'];

        self::assertSame(['bl_anna', 'bl_bert'], array_column($liste, 'username'), 'carl: nur 1 Spiel; dora: will nicht.');
        self::assertSame([6, 2], array_column($liste, 'punkte'));
        self::assertSame([2, 2], array_column($liste, 'spiele'));

        self::assertSame(2, $ansicht['minSpiele']);
        self::assertSame(1, BestenlisteService::anzahlUnterMindestanzahl($ansicht['konten'], 2));
    }

    public function testFruehestesGewertetesSpielBegrenztDasZurueckblaettern(): void
    {
        $repo = static::getContainer()->get(SpielTeilnehmerRepository::class);
        $anna = $this->neuerUser('bl_frueh');
        $tisch = $this->beitritt->erstelleTisch($anna, 'Früh', ZugangsModusTyp::OFFEN);

        // Nicht global gewertet: zählt für die Grenze nicht.
        $this->buche($tisch, WertungsKategorie::DDV, false, self::utc('2001-01-01 12:00'), [[$anna, 1]]);
        $this->buche($tisch, WertungsKategorie::DDV, true, self::utc('2002-06-15 12:00'), [[$anna, 1]]);

        self::assertSame('2002-06-15', $repo->findeFruehestesGewertetesSpielende()?->format('Y-m-d'));
    }

    /**
     * Legt ein beendetes Spiel mit fertiger Wertung an.
     *
     * @param list<array{0: User, 1: int}> $buchungen
     */
    private function buche(
        Tisch $tisch,
        WertungsKategorie $kategorie,
        bool $global,
        \DateTimeImmutable $beendetAm,
        array $buchungen,
        bool $mitAbrechnung = true,
    ): void {
        $spiel = (new Spiel())
            ->setTisch($tisch)
            ->setStatus(SpielStatus::BEENDET)
            ->setKategorie($kategorie)
            ->setGlobalGewertet($global)
            ->setBeendetAm($beendetAm)
            ->setWertungDetails($mitAbrechnung ? ['spielwert' => 1] : null);
        $this->em->persist($spiel);

        $sitz = 1;
        foreach ($buchungen as [$user, $delta]) {
            $this->em->persist((new SpielTeilnehmer())->setSpiel($spiel)->setSitzplatz($sitz++)->setPunkteDelta($delta)->setUser($user));
        }
        $this->em->flush();
    }
}
