<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Infrastructure\Logger\MercureLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class LobbyMercurePublisher
{
    public const TOPIC = 'https://doppelkopf.de/lobby/tische';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureLogger $logger,
    ) {}

    public function lobbyAktualisiert(): void
    {
        try {
            $this->hub->publish(new Update(
                self::TOPIC,
                json_encode(['typ' => 'LOBBY_AKTUALISIERT'], JSON_THROW_ON_ERROR),
            ));
            $this->logger->updateVeroeffentlicht(self::TOPIC, 'LOBBY_AKTUALISIERT');
        } catch (\Throwable $e) {
            $this->logger->veroeffentlichungFehlgeschlagen(self::TOPIC, $e->getMessage());
        }
    }
}
