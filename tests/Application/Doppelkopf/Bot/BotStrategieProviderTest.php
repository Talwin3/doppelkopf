<?php

declare(strict_types=1);

namespace App\Tests\Application\Doppelkopf\Bot;

use App\Application\Doppelkopf\Bot\BotStrategie;
use App\Application\Doppelkopf\Bot\BotStrategieProvider;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\BotStaerke;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BotStrategieProviderTest extends TestCase
{
    public function testLiefertRegistrierteStrategieFuerIhreStaerke(): void
    {
        $anfaenger = $this->strategie(BotStaerke::ANFAENGER);
        $profi     = $this->strategie(BotStaerke::PROFI);

        $provider = new BotStrategieProvider([$anfaenger, $profi], new NullLogger());

        self::assertSame($anfaenger, $provider->fuer(BotStaerke::ANFAENGER));
        self::assertSame($profi, $provider->fuer(BotStaerke::PROFI));
    }

    public function testFaelltBeiFehlenderStrategieAufDefaultZurueck(): void
    {
        $anfaenger = $this->strategie(BotStaerke::ANFAENGER);

        $provider = new BotStrategieProvider([$anfaenger], new NullLogger());

        // Für FORTGESCHRITTEN ist keine Strategie registriert → Default (Anfänger).
        self::assertSame($anfaenger, $provider->fuer(BotStaerke::FORTGESCHRITTEN));
    }

    public function testWirftOhneJedeStrategie(): void
    {
        $provider = new BotStrategieProvider([], new NullLogger());

        $this->expectException(\LogicException::class);
        $provider->fuer(BotStaerke::ANFAENGER);
    }

    private function strategie(BotStaerke $staerke): BotStrategie
    {
        return new class($staerke) implements BotStrategie {
            public function __construct(private readonly BotStaerke $staerke) {}

            public function staerke(): BotStaerke
            {
                return $this->staerke;
            }

            public function waehleKarte(Spiel $spiel, SpielTeilnehmer $teilnehmer, array $erlaubte): Karte
            {
                return $erlaubte[0];
            }
        };
    }
}
