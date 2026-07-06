<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\ArmutHeuristik;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\BotStaerke;
use PHPUnit\Framework\TestCase;

final class ArmutHeuristikTest extends TestCase
{
    private ArmutHeuristik $heuristik;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->heuristik = new ArmutHeuristik();
        $this->ordnung   = new NormalspielTrumpfOrdnung();
    }

    private function karten(array $ids): array
    {
        return array_map(fn(string $id) => Karte::vonId($id), $ids);
    }

    public function testAnfaengerNimmtErstAbFuenfTruempfenAn(): void
    {
        $vier = $this->karten(['KREUZ_DAME_1', 'PIK_DAME_1', 'KREUZ_BUBE_1', 'KARO_NEUN_1', 'PIK_ASS_1', 'HERZ_ASS_1']);
        $fuenf = $this->karten(['KARO_ASS_1', 'KARO_ZEHN_1', 'KARO_KOENIG_1', 'KARO_NEUN_1', 'KREUZ_BUBE_1', 'PIK_ASS_1']);

        self::assertFalse($this->heuristik->willAnnehmen(BotStaerke::ANFAENGER, $vier, $this->ordnung));
        self::assertTrue($this->heuristik->willAnnehmen(BotStaerke::ANFAENGER, $fuenf, $this->ordnung));
    }

    public function testStaerkerNimmtAuchBeiVierTruempfenMitVielenHohenAn(): void
    {
        // 4 Trümpfe, davon 3 hohe (2 Damen + 1 Bube).
        $hand = $this->karten(['KREUZ_DAME_1', 'PIK_DAME_1', 'KREUZ_BUBE_1', 'KARO_NEUN_1', 'PIK_ASS_1', 'HERZ_ASS_1']);

        self::assertTrue($this->heuristik->willAnnehmen(BotStaerke::FORTGESCHRITTEN, $hand, $this->ordnung));
    }

    public function testSchwacheHandLehntAbUnabhaengigVonStaerke(): void
    {
        $hand = $this->karten(['KARO_NEUN_1', 'KREUZ_BUBE_1', 'PIK_ASS_1', 'HERZ_ASS_1', 'KREUZ_ASS_1', 'PIK_ZEHN_1']);

        self::assertFalse($this->heuristik->willAnnehmen(BotStaerke::PROFI, $hand, $this->ordnung));
    }

    public function testRueckgabeGibtGenauDieVerlangteAnzahl(): void
    {
        $hand = $this->karten(['PIK_KOENIG_1', 'PIK_NEUN_1', 'KREUZ_NEUN_1', 'KARO_DAME_1', 'KARO_ASS_1']);

        $zurueck = $this->heuristik->kartenZurueckgeben(BotStaerke::PROFI, $hand, 2, $this->ordnung);

        self::assertCount(2, $zurueck);
        foreach ($zurueck as $id) {
            self::assertContains($id, array_map(fn(Karte $k) => $k->id(), $hand));
        }
    }

    public function testStaerkerRaeumtSchwacheFarbeKomplettAbAnfaengerNicht(): void
    {
        // Pik (König 4 + Neun 0) ist die schwächste Farbe mit 2 Karten; Kreuz hat drei
        // niedrige Karten und passt nicht ins Budget 2. Stärkere Bots geben ganz Pik ab
        // (inkl. der 4-Augen-Karte), Anfänger nur die augenärmsten Nullkarten.
        $hand = $this->karten([
            'PIK_KOENIG_1', 'PIK_NEUN_1',
            'KREUZ_NEUN_1', 'KREUZ_NEUN_2', 'KREUZ_KOENIG_1',
            'KARO_DAME_1', 'KARO_ASS_1',
        ]);

        $profi     = $this->heuristik->kartenZurueckgeben(BotStaerke::PROFI, $hand, 2, $this->ordnung);
        $anfaenger = $this->heuristik->kartenZurueckgeben(BotStaerke::ANFAENGER, $hand, 2, $this->ordnung);

        self::assertContains('PIK_KOENIG_1', $profi, 'Profi räumt Pik komplett ab (inkl. König).');
        self::assertContains('PIK_NEUN_1', $profi);
        self::assertNotContains('PIK_KOENIG_1', $anfaenger, 'Anfänger behält die 4-Augen-Karte.');
    }

    public function testRueckgabeGreiftAufTruempfeZurueckWennNichtGenugNichtTruempfe(): void
    {
        // Nur eine Nicht-Trumpf-Karte, aber 3 sollen zurück → 2 niedrigste Trümpfe ergänzen.
        $hand = $this->karten(['PIK_NEUN_1', 'KARO_NEUN_1', 'KARO_KOENIG_1', 'KARO_ASS_1', 'KREUZ_DAME_1']);

        $zurueck = $this->heuristik->kartenZurueckgeben(BotStaerke::ANFAENGER, $hand, 3, $this->ordnung);

        self::assertCount(3, $zurueck);
        self::assertContains('PIK_NEUN_1', $zurueck);
    }
}
