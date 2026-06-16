<?php

declare(strict_types=1);

namespace App\Infrastructure\Logger;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Logging für Mercure-Publishing-Ereignisse.
 */
final class MercureLogger
{
    public function __construct(
        #[WithMonologChannel('mercure')]
        private readonly LoggerInterface $logger,
    ) {}

    public function updateVeroeffentlicht(string $topic, string $typ): void
    {
        $this->logger->debug('Mercure-Update veröffentlicht', [
            'topic' => $topic,
            'typ'   => $typ,
        ]);
    }

    public function veroeffentlichungFehlgeschlagen(string $topic, string $fehler): void
    {
        $this->logger->error('Mercure-Veröffentlichung fehlgeschlagen', [
            'topic'  => $topic,
            'fehler' => $fehler,
        ]);
    }

    public function privatUpdateVeroeffentlicht(string $userId, string $typ): void
    {
        $this->logger->debug('Privates Mercure-Update an Spieler', [
            'user_id' => $userId,
            'typ'     => $typ,
        ]);
    }
}
