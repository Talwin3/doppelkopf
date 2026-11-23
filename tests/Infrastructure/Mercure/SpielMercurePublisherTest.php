<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mercure;

use App\Entity\Tisch;
use App\Infrastructure\Logger\MercureLogger;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SpielMercurePublisherTest extends TestCase
{
    /** Der speichernde Client erkennt sein eigenes Event an der mitgeschickten Kennung. */
    public function testTischEinstellungenTragenDieKennungDerSpeicherung(): void
    {
        self::assertSame(
            ['typ' => 'TISCH_EINSTELLUNGEN', 'regelwerk' => true, 'aenderung' => 'abc123'],
            $this->gesendet(static fn(SpielMercurePublisher $p, Tisch $t) => $p->tischAktualisiert($t, true, 'abc123')),
        );
        self::assertSame(
            ['typ' => 'TISCH_EINSTELLUNGEN', 'regelwerk' => false],
            $this->gesendet(static fn(SpielMercurePublisher $p, Tisch $t) => $p->tischAktualisiert($t)),
        );
    }

    /** @return array<string, mixed> */
    private function gesendet(callable $aufruf): array
    {
        $hub = $this->createMock(HubInterface::class);
        $update = null;
        $hub->expects(self::once())->method('publish')
            ->willReturnCallback(static function (Update $u) use (&$update): string {
                $update = $u;
                return 'id';
            });

        $aufruf(new SpielMercurePublisher($hub, new MercureLogger(new NullLogger())), new Tisch());

        self::assertInstanceOf(Update::class, $update);
        return json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
    }
}
