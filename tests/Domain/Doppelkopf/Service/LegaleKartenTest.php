<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\LegaleKarten;
use App\Domain\Doppelkopf\ValueObject\Karte;
use PHPUnit\Framework\TestCase;

final class LegaleKartenTest extends TestCase
{
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->ordnung = new NormalspielTrumpfOrdnung();
    }

    private function k(string $id): Karte
    {
        return Karte::vonId($id);
    }

    /** @param Karte[] $karten */
    private function ids(array $karten): array
    {
        return array_map(fn(Karte $k) => $k->id(), $karten);
    }

    public function testBeimAnspielenAlleKartenErlaubt(): void
    {
        $hand = [$this->k('KREUZ_ASS_1'), $this->k('KARO_DAME_1')];

        self::assertSame($this->ids($hand), $this->ids(LegaleKarten::fuer($hand, null, $this->ordnung)));
    }

    public function testFehlfarbeMussBedientWerden(): void
    {
        $hand = [$this->k('KREUZ_ASS_1'), $this->k('KREUZ_NEUN_1'), $this->k('KARO_DAME_1')];
        $legale = LegaleKarten::fuer($hand, $this->k('KREUZ_KOENIG_1'), $this->ordnung);

        self::assertSame(['KREUZ_ASS_1', 'KREUZ_NEUN_1'], $this->ids($legale));
    }

    public function testOhneAnfarbeAllesErlaubt(): void
    {
        $hand = [$this->k('PIK_ASS_1'), $this->k('KARO_DAME_1')];
        $legale = LegaleKarten::fuer($hand, $this->k('KREUZ_KOENIG_1'), $this->ordnung);

        self::assertSame($this->ids($hand), $this->ids($legale));
    }

    public function testAngespielterTrumpfMussMitTrumpfBedientWerden(): void
    {
        // KARO ist Trumpf im Normalspiel; KARO_DAME ist ebenfalls Trumpf.
        $hand = [$this->k('KARO_DAME_1'), $this->k('KREUZ_ASS_1')];
        $legale = LegaleKarten::fuer($hand, $this->k('KARO_NEUN_1'), $this->ordnung);

        self::assertSame(['KARO_DAME_1'], $this->ids($legale));
    }
}
