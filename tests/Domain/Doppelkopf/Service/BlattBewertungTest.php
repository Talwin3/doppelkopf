<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\BlattBewertung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use PHPUnit\Framework\TestCase;

/**
 * Geprüft wird gegen die Beispielblätter aus dem Originaltext des Essener Systems
 * (Kapitel 3.2). Deren Absolutwerte sind allerdings **kein** belastbarer Anker: die
 * Tabelle ist in der Quelle beschädigt, und mindestens eine Beispielrechnung ist auch im
 * Original falsch. Verlangt wird deshalb die **Rangfolge** – die Blätter, mit denen das
 * System ansagen will, müssen deutlich über denen liegen, mit denen es passt.
 */
final class BlattBewertungTest extends TestCase
{
    private BlattBewertung $bewertung;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->bewertung = new BlattBewertung();
        $this->ordnung   = new NormalspielTrumpfOrdnung();
    }

    /** @param string[] $ids */
    private function hand(array $ids): array
    {
        return array_map(static fn(string $id) => Karte::vonId($id), $ids);
    }

    /** Originalblatt 2: „≈ 52,5 = kein RE" – das schwächste der Beispiele. */
    private function schwachesBlatt(): array
    {
        return $this->hand([
            'KREUZ_DAME_1', 'KARO_DAME_1', 'KREUZ_BUBE_1', 'KREUZ_BUBE_2', 'PIK_BUBE_1',
            'HERZ_BUBE_1', 'KARO_BUBE_1', 'KARO_ZEHN_1', 'KARO_KOENIG_1', 'KARO_NEUN_1',
            'KREUZ_KOENIG_1', 'PIK_NEUN_1',
        ]);
    }

    /**
     * Kapitel 3.3, Blatt 1 – ein Blatt für die *vorzeitige* Erstansage, für das das System
     * „über 100" Augen ansetzt. Bewusst eines ohne Spielverlauf: die Beispiele aus 3.2
     * enthalten in ihrer Endsumme meist schon erspielte Augen (Blatt 5 etwa 31 aus einem
     * gelaufenen Ass) und taugen deshalb nicht als reiner Blattwert.
     */
    private function starkesBlatt(): array
    {
        return $this->hand([
            'HERZ_ZEHN_1', 'KREUZ_DAME_1', 'HERZ_DAME_1', 'HERZ_DAME_2', 'KREUZ_BUBE_1',
            'PIK_BUBE_1', 'PIK_BUBE_2', 'KARO_BUBE_1', 'KARO_ZEHN_1', 'PIK_ASS_1',
            'PIK_NEUN_1', 'HERZ_KOENIG_1',
        ]);
    }

    public function testStarkesBlattSchlaegtSchwaches(): void
    {
        $schwach = $this->bewertung->augen($this->schwachesBlatt(), $this->ordnung);
        $stark   = $this->bewertung->augen($this->starkesBlatt(), $this->ordnung);

        self::assertGreaterThan(
            $schwach + 25,
            $stark,
            'Das Ansage-Blatt muss deutlich über dem Pass-Blatt liegen.',
        );
    }

    /**
     * Die Bewertung soll in der Größenordnung des Systems liegen (Durchschnitt ~68 Augen
     * für RE, ~52 für KONTRA) – sonst passen dessen Schwellen 75/85 von vornherein nicht.
     */
    public function testBewertungLiegtInPlausiblerGroessenordnung(): void
    {
        foreach ([$this->schwachesBlatt(), $this->starkesBlatt()] as $blatt) {
            $augen = $this->bewertung->augen($blatt, $this->ordnung);
            self::assertGreaterThan(20.0, $augen);
            self::assertLessThan(180.0, $augen, 'Mehr als 180 Augen kann ein Blatt nicht erwarten.');
        }
    }

    public function testDulleUndDamenZaehlen(): void
    {
        $ohne = $this->hand(['KARO_NEUN_1', 'KARO_NEUN_2', 'KREUZ_NEUN_1', 'PIK_NEUN_1']);
        $mit  = $this->hand(['HERZ_ZEHN_1', 'KREUZ_DAME_1', 'KREUZ_NEUN_1', 'PIK_NEUN_1']);

        self::assertGreaterThan(
            $this->bewertung->augen($ohne, $this->ordnung) + 25,
            $this->bewertung->augen($mit, $this->ordnung),
        );
    }

    /** Ein Ass ist umso mehr wert, je kürzer die Farbe besetzt ist. */
    public function testKurzeFarbeMachtDasAssWertvoller(): void
    {
        $kurz = $this->hand(['KREUZ_ASS_1', 'KARO_DAME_1', 'KARO_BUBE_1', 'KARO_ZEHN_1']);
        $lang = $this->hand(['KREUZ_ASS_1', 'KREUZ_ZEHN_1', 'KREUZ_KOENIG_1', 'KREUZ_NEUN_1']);

        self::assertGreaterThan(
            $this->bewertung->augen($lang, $this->ordnung),
            $this->bewertung->augen($kurz, $this->ordnung),
        );
    }

    public function testNachAnsageLaufenAsseHaeufiger(): void
    {
        $blatt = $this->hand(['KREUZ_ASS_1', 'KREUZ_ZEHN_1', 'KARO_DAME_1', 'KARO_BUBE_1']);

        self::assertGreaterThan(
            $this->bewertung->augen($blatt, $this->ordnung, false),
            $this->bewertung->augen($blatt, $this->ordnung, true),
        );
    }

    public function testLeeresBlattIstNull(): void
    {
        self::assertSame(0.0, $this->bewertung->augen([], $this->ordnung));
    }
}
