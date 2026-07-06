<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\SpielVariante;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * PIMC-Engine. Da die Determinisierung zufällig ist, werden Szenarien gewählt,
 * in denen die beste Karte über alle Welten hinweg eindeutig ist.
 */
final class ProfiHeuristikTest extends TestCase
{
    private ProfiHeuristik $profi;
    private NormalspielTrumpfOrdnung $ordnung;

    protected function setUp(): void
    {
        $this->profi   = new ProfiHeuristik(new StichGewinner());
        $this->ordnung = new NormalspielTrumpfOrdnung();
    }

    private function k(string $id): Karte
    {
        return Karte::vonId($id);
    }

    public function testStichtWertvollenStichAlsLetzterSpieler(): void
    {
        // Bot (Sitz 1, KONTRA) ist letzter Spieler. Sitz 2 (RE) führt mit Kreuz-Ass;
        // 25 Augen liegen im Stich. Bot kann mit Karo-Neun (Trumpf) stechen oder Pik
        // abwerfen. Restkarten sind augenlos → Stechen sichert eindeutig 25 Augen.
        $offenerStich = [
            2 => $this->k('KREUZ_ASS_1'),
            3 => $this->k('KREUZ_ZEHN_1'),
            4 => $this->k('KREUZ_KOENIG_1'),
        ];
        $eigeneHand = [$this->k('KARO_NEUN_1'), $this->k('PIK_NEUN_1')];
        $ungesehene = [$this->k('PIK_NEUN_2'), $this->k('HERZ_NEUN_1'), $this->k('KARO_NEUN_2')];

        $g = SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung);

        $gewaehlt = $this->profi->entscheide(
            erlaubte: $eigeneHand,
            eigeneHand: $eigeneHand,
            eigenerSitz: 1,
            eigenesTeam: Team::KONTRA,
            offenerStich: $offenerStich,
            restHandGroessen: [2 => 1, 3 => 1, 4 => 1],
            ungesehene: $ungesehene,
            gedaechtnis: $g,
            // SOLO nur, um die bekannten Teams für die Wertung zu nutzen (Sitz 2 = RE).
            variante: SpielVariante::SOLO_DAMEN,
            bekannteTeams: [1 => Team::KONTRA, 2 => Team::RE, 3 => Team::KONTRA, 4 => Team::RE],
            kreuzDameGespieltVon: [],
            ordnung: $this->ordnung,
            zweiteDulle: false,
            rollouts: 20,
        );

        self::assertSame('KARO_NEUN_1', $gewaehlt->id(), 'Bot sollte den 25-Augen-Stich mit Trumpf holen.');
    }

    public function testGibtImmerEineErlaubteKarteZurueck(): void
    {
        $eigeneHand = [$this->k('KREUZ_ASS_1'), $this->k('KARO_DAME_1'), $this->k('PIK_NEUN_1')];
        $ungesehene = [
            $this->k('KREUZ_ZEHN_1'), $this->k('KREUZ_KOENIG_1'), $this->k('PIK_ASS_1'),
            $this->k('HERZ_KOENIG_1'), $this->k('KARO_ASS_1'), $this->k('PIK_ZEHN_1'),
            $this->k('HERZ_NEUN_1'), $this->k('KARO_NEUN_1'), $this->k('PIK_KOENIG_1'),
        ];
        $g = SpielGedaechtnis::ausHistorie([], [], $eigeneHand, $this->ordnung);

        $gewaehlt = $this->profi->entscheide(
            erlaubte: $eigeneHand,
            eigeneHand: $eigeneHand,
            eigenerSitz: 1,
            eigenesTeam: Team::RE,
            offenerStich: [],
            restHandGroessen: [2 => 3, 3 => 3, 4 => 3],
            ungesehene: $ungesehene,
            gedaechtnis: $g,
            variante: SpielVariante::NORMALSPIEL,
            bekannteTeams: [1 => Team::RE, 2 => null, 3 => null, 4 => null],
            kreuzDameGespieltVon: [],
            ordnung: $this->ordnung,
            zweiteDulle: false,
            rollouts: 15,
        );

        self::assertContains($gewaehlt->id(), array_map(fn(Karte $k) => $k->id(), $eigeneHand));
    }
}
