<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Reine Logik-Tests für die regelbasierte Bot-Strategie. Kein Kernel/DB nötig.
 * Standardaufstellung der Teams: 1+3 = RE, 2+4 = KONTRA.
 */
final class BotHeuristikTest extends TestCase
{
    private BotHeuristik $heuristik;
    private TrumpfOrdnung $ordnung;

    /** @var array<int, Team> */
    private array $teams;

    protected function setUp(): void
    {
        $this->heuristik = new BotHeuristik();
        $this->ordnung   = new NormalspielTrumpfOrdnung();
        $this->teams = [
            1 => Team::RE,
            2 => Team::KONTRA,
            3 => Team::RE,
            4 => Team::KONTRA,
        ];
    }

    private function k(string $id): Karte
    {
        return Karte::vonId($id);
    }

    public function testAnspielenBevorzugtFehlfarbenAss(): void
    {
        $erlaubte = [$this->k('KREUZ_KOENIG_1'), $this->k('KREUZ_ASS_1'), $this->k('KARO_DAME_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, [], Team::RE, $this->teams, $this->ordnung);

        self::assertSame('KREUZ_ASS_1', $gewaehlt->id());
    }

    public function testAnspielenOhneAssWirftNiedrigsteFehlfarbeAb(): void
    {
        // Kein Fehlfarben-Ass → niedrigste Augen, Trumpf (Kreuz-Dame) wird geschont.
        $erlaubte = [$this->k('KREUZ_KOENIG_1'), $this->k('PIK_NEUN_1'), $this->k('KREUZ_DAME_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, [], Team::RE, $this->teams, $this->ordnung);

        self::assertSame('PIK_NEUN_1', $gewaehlt->id());
    }

    public function testAnspielenMitNurTrumpfSpieltNiedrigstenTrumpf(): void
    {
        $erlaubte = [$this->k('KARO_ASS_1'), $this->k('KREUZ_DAME_1'), $this->k('KARO_NEUN_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, [], Team::RE, $this->teams, $this->ordnung);

        self::assertSame('KARO_NEUN_1', $gewaehlt->id());
    }

    public function testPartnerFuehrtUndBotIstLetzterSchmiertHoechsteAugen(): void
    {
        // Spielreihenfolge 4 → 1 → 2, Bot ist Sitz 3 (letzter). Partner = Sitz 1 (RE)
        // führt mit Kreuz-Ass. Bot schmiert die höchsten Augen (Kreuz-Zehn).
        $stich = [
            4 => $this->k('KREUZ_NEUN_1'),
            1 => $this->k('KREUZ_ASS_1'),
            2 => $this->k('KREUZ_KOENIG_1'),
        ];
        $erlaubte = [$this->k('KREUZ_NEUN_2'), $this->k('KREUZ_ZEHN_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, $stich, Team::RE, $this->teams, $this->ordnung);

        self::assertSame('KREUZ_ZEHN_1', $gewaehlt->id());
    }

    public function testPartnerFuehrtUndBotNichtLetzterWirftSparsamAb(): void
    {
        // Spielreihenfolge 3 → 4, Bot ist Sitz 1 (3. von 4 → nicht letzter).
        // Partner = Sitz 3 (RE) führt mit Kreuz-Ass. Bot wirft sparsam ab.
        $stich = [
            3 => $this->k('KREUZ_ASS_1'),
            4 => $this->k('KREUZ_KOENIG_1'),
        ];
        $erlaubte = [$this->k('KREUZ_ZEHN_1'), $this->k('KREUZ_NEUN_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, $stich, Team::RE, $this->teams, $this->ordnung);

        self::assertSame('KREUZ_NEUN_1', $gewaehlt->id());
    }

    public function testGegnerFuehrtBotSchlaegtBilligMitNiedrigstemTrumpf(): void
    {
        // Gegner (Sitz 2) führt mit Kreuz-Ass, viele Augen im Stich. Bot (Sitz 1, letzter)
        // ist kreuzfrei und sticht mit dem billigsten Trumpf (Karo-Neun statt Karo-Ass).
        $stich = [
            2 => $this->k('KREUZ_ASS_1'),
            3 => $this->k('KREUZ_NEUN_1'),
            4 => $this->k('KREUZ_KOENIG_1'),
        ];
        $erlaubte = [$this->k('KARO_ASS_1'), $this->k('KARO_NEUN_1'), $this->k('HERZ_KOENIG_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, $stich, Team::RE, $this->teams, $this->ordnung);

        self::assertSame('KARO_NEUN_1', $gewaehlt->id());
    }

    public function testGegnerFuehrtBotKannNichtStechenWirftNiedrigsteAugenAb(): void
    {
        // Gegner sticht mit Trumpf (Karo-Dame). Bot hat keinen Trumpf → wirft die
        // augenärmste Karte ab und behält das Ass.
        $stich = [
            2 => $this->k('KARO_DAME_1'),
        ];
        $erlaubte = [$this->k('KREUZ_ASS_1'), $this->k('PIK_NEUN_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, $stich, Team::RE, $this->teams, $this->ordnung);

        self::assertSame('PIK_NEUN_1', $gewaehlt->id());
    }

    public function testGegnerFuehrtAberWenigAugenUndNichtLetzterStichtNicht(): void
    {
        // Nur 0 Augen im Stich, Bot ist nicht letzter → er verschwendet keinen Trumpf,
        // sondern wirft die augenärmste Karte ab (Herz-Neun statt Karo-Dame zu stechen).
        $stich = [
            2 => $this->k('KREUZ_NEUN_1'),
        ];
        $erlaubte = [$this->k('HERZ_KOENIG_1'), $this->k('HERZ_NEUN_1'), $this->k('KARO_DAME_1')];

        $gewaehlt = $this->heuristik->entscheide($erlaubte, $stich, Team::RE, $this->teams, $this->ordnung);

        self::assertSame('HERZ_NEUN_1', $gewaehlt->id());
    }
}
