<?php

declare(strict_types=1);

namespace App\Tests\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\Service\BotNamenProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reine Logik-Tests für die zufällige Bot-Namensvergabe. Kein Kernel/DB nötig.
 */
final class BotNamenProviderTest extends TestCase
{
    private BotNamenProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new BotNamenProvider();
    }

    public function testLiefertNichtLeerenNamen(): void
    {
        $name = $this->provider->zufaelligerName();

        self::assertNotSame('', $name);
    }

    public function testMeidetBereitsVergebeneNamen(): void
    {
        // Wir sperren fast alle Namen und lassen nur einen gezielt frei.
        // Über viele Durchläufe muss immer der einzige freie Name kommen.
        $alle = $this->ermittleAlleNamen();
        $frei = $alle[0];
        $gesperrt = array_slice($alle, 1);

        for ($i = 0; $i < 50; $i++) {
            self::assertSame($frei, $this->provider->zufaelligerName($gesperrt));
        }
    }

    public function testFaelltBeiKomplettVergebenenNamenAufVollListeZurueck(): void
    {
        $alle = $this->ermittleAlleNamen();

        // Auch wenn alle Namen "vergeben" sind, darf kein Leerstring entstehen.
        $name = $this->provider->zufaelligerName($alle);

        self::assertContains($name, $alle);
    }

    /**
     * Sammelt die komplette interne Namensliste durch viele Ziehungen ein.
     *
     * @return string[]
     */
    private function ermittleAlleNamen(): array
    {
        $gesehen = [];
        for ($i = 0; $i < 5000; $i++) {
            $gesehen[$this->provider->zufaelligerName()] = true;
        }

        return array_keys($gesehen);
    }
}
