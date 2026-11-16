<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Tisch;
use App\Enum\WertungsKategorie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WertungsKategorieTest extends TestCase
{
    public function testNeuerTischSpieltNachDdv(): void
    {
        self::assertSame(
            WertungsKategorie::DDV,
            WertungsKategorie::fuerRegelwerk((new Tisch())->getRegelEinstellungen()),
        );
    }

    /**
     * Jede Regel des Tischs muss eingeordnet sein. Schlägt dieser Test fehl, ist eine
     * neue Regel dazugekommen: Sie gehört in {@see WertungsKategorie::DDV_REGELN}
     * (mit ihrem DDV-Wert) oder in {@see WertungsKategorie::DARSTELLUNGS_SCHLUESSEL}.
     */
    public function testJedeTischregelIstEingeordnet(): void
    {
        $bekannt = [...array_keys(WertungsKategorie::DDV_REGELN), ...WertungsKategorie::DARSTELLUNGS_SCHLUESSEL];

        self::assertSame(
            [],
            array_values(array_diff(array_keys((new Tisch())->getRegelEinstellungen()), $bekannt)),
        );
    }

    public function testDarstellungsSchalterAendertDieKategorieNicht(): void
    {
        $regeln = WertungsKategorie::DDV_REGELN + ['augen_mouseover' => true];

        self::assertSame(WertungsKategorie::DDV, WertungsKategorie::fuerRegelwerk($regeln));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function abweichungen(): iterable
    {
        yield 'Solist kommt raus'   => ['solist_kommt_raus', true];
        yield 'Schweinchen'         => ['schweinchen', true];
        yield 'Superschweinchen'    => ['superschweinchen', true];
        yield 'Armut'               => ['armut', true];
        yield 'Zweite Dulle sticht' => ['zweite_dulle_sticht', true];
        yield 'ein Solo verboten'   => ['soli_erlaubt', ['SOLO_BUBEN', 'SOLO_DAMEN', 'SOLO_KARO', 'SOLO_HERZ', 'SOLO_PIK', 'SOLO_KREUZ']];
        yield 'keine Soli'          => ['soli_erlaubt', []];
    }

    #[DataProvider('abweichungen')]
    public function testJedeAbweichungMitNeunernIstSonderregel(string $schluessel, mixed $wert): void
    {
        $regeln = array_replace(WertungsKategorie::DDV_REGELN, [$schluessel => $wert]);

        self::assertSame(WertungsKategorie::SONDERREGELN, WertungsKategorie::fuerRegelwerk($regeln));
    }

    #[DataProvider('abweichungen')]
    public function testOhneNeunerSchlaegtJedeAbweichung(string $schluessel, mixed $wert): void
    {
        $regeln = array_replace(WertungsKategorie::DDV_REGELN, [$schluessel => $wert, 'ohne_neuner' => true]);

        self::assertSame(WertungsKategorie::OHNE_NEUNER, WertungsKategorie::fuerRegelwerk($regeln));
    }

    public function testUnbekannteRegelZaehltVorsichtshalberAlsSonderregel(): void
    {
        $regeln = WertungsKategorie::DDV_REGELN + ['neue_hausregel' => true];

        self::assertSame(WertungsKategorie::SONDERREGELN, WertungsKategorie::fuerRegelwerk($regeln));
    }
}
