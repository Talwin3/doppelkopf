<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Regel\Normalspiel\NormalspielTrumpfOrdnung;
use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Team;
use PHPUnit\Framework\TestCase;

/**
 * Reine Logik-Tests für die fortgeschrittene Bot-Strategie mit Kartengedächtnis.
 * Standardaufstellung: 1+3 = RE, 2+4 = KONTRA.
 */
final class FortgeschritteneHeuristikTest extends TestCase
{
    private FortgeschritteneHeuristik $heuristik;
    private TrumpfOrdnung $ordnung;

    /** @var array<int, Team> */
    private array $teams;

    protected function setUp(): void
    {
        $this->heuristik = new FortgeschritteneHeuristik();
        $this->ordnung   = new NormalspielTrumpfOrdnung();
        $this->teams = [1 => Team::RE, 2 => Team::KONTRA, 3 => Team::RE, 4 => Team::KONTRA];
    }

    private function k(string $id): Karte
    {
        return Karte::vonId($id);
    }

    /**
     * @param list<array{sitzplatz:int, stichNr:int, position:int, karte:Karte}> $ereignisse
     * @param Karte[] $eigeneHand
     */
    private function gedaechtnis(array $ereignisse, array $eigeneHand): SpielGedaechtnis
    {
        // Ein großzügiges Universum reicht; ungesehene = Universum − gespielt − Hand.
        $universum = array_map(fn(Karte $k) => $k, $eigeneHand);
        foreach ($ereignisse as $e) {
            $universum[] = $e['karte'];
        }
        // Ein paar hohe Trümpfe als "bei Gegnern" hinzufügen.
        foreach (['KREUZ_DAME_1', 'KREUZ_DAME_2', 'PIK_DAME_1'] as $id) {
            $universum[] = $this->k($id);
        }

        return SpielGedaechtnis::ausHistorie($ereignisse, $universum, $eigeneHand, $this->ordnung);
    }

    /** Vorstich: Kreuz wird angespielt, Sitz 2 trumpft ab → Sitz 2 ist frei in Kreuz. */
    private function kreuzVoidStich(): array
    {
        return [
            ['sitzplatz' => 3, 'stichNr' => 1, 'position' => 1, 'karte' => $this->k('KREUZ_KOENIG_1')],
            ['sitzplatz' => 4, 'stichNr' => 1, 'position' => 2, 'karte' => $this->k('KREUZ_NEUN_1')],
            ['sitzplatz' => 1, 'stichNr' => 1, 'position' => 3, 'karte' => $this->k('KREUZ_ZEHN_1')],
            ['sitzplatz' => 2, 'stichNr' => 1, 'position' => 4, 'karte' => $this->k('KARO_BUBE_1')], // Trumpf statt Kreuz
        ];
    }

    public function testSpieltKeinAssWennGegnerInDerFarbeFreiIst(): void
    {
        $hand = [$this->k('KREUZ_ASS_1'), $this->k('PIK_NEUN_1')];
        $g = $this->gedaechtnis($this->kreuzVoidStich(), $hand);

        $gewaehlt = $this->heuristik->entscheide($hand, [], Team::RE, $this->teams, $this->ordnung, $g);

        self::assertNotSame('KREUZ_ASS_1', $gewaehlt->id(), 'Ass darf nicht in eine vom Gegner gestochene Farbe gespielt werden.');
        self::assertSame('PIK_NEUN_1', $gewaehlt->id());
    }

    public function testSpieltAssWennFarbeSicherIst(): void
    {
        // Kein Vorstich → niemand ist frei; Kreuz-Ass ist ein sicherer Stich.
        $hand = [$this->k('KREUZ_ASS_1'), $this->k('PIK_NEUN_1')];
        $g = $this->gedaechtnis([], $hand);

        $gewaehlt = $this->heuristik->entscheide($hand, [], Team::RE, $this->teams, $this->ordnung, $g);

        self::assertSame('KREUZ_ASS_1', $gewaehlt->id());
    }

    public function testSchmiertNichtWennPartnerStichNichtSicherIst(): void
    {
        // Partner (Sitz 3) führt mit Kreuz-König, Bot (Sitz 1) ist zweiter Spieler.
        // Zwei Gegner kommen noch – der Stich ist nicht sicher → sparsam abwerfen.
        $stich = [3 => $this->k('KREUZ_KOENIG_1')];
        $hand  = [$this->k('KREUZ_ASS_1'), $this->k('KREUZ_NEUN_1')];
        $g = $this->gedaechtnis([], $hand);

        $gewaehlt = $this->heuristik->entscheide($hand, $stich, Team::RE, $this->teams, $this->ordnung, $g);

        self::assertSame('KREUZ_NEUN_1', $gewaehlt->id(), 'Bei unsicherem Partnerstich keine 11 Augen verschenken.');
    }

    public function testSchmiertWennBotLetzterSpielerIst(): void
    {
        // Partner (Sitz 3) führt, Bot (Sitz 1) ist letzter Spieler → Stich sicher, schmieren.
        $stich = [
            3 => $this->k('KREUZ_KOENIG_1'),
            4 => $this->k('KREUZ_NEUN_1'),
            2 => $this->k('KREUZ_NEUN_2'),
        ];
        $hand = [$this->k('KREUZ_ASS_1'), $this->k('PIK_NEUN_1')];
        $g = $this->gedaechtnis([], $hand);

        $gewaehlt = $this->heuristik->entscheide($hand, $stich, Team::RE, $this->teams, $this->ordnung, $g);

        self::assertSame('KREUZ_ASS_1', $gewaehlt->id(), 'Als letzter Spieler die 11 Augen schmieren.');
    }
}
