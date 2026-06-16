<?php

declare(strict_types=1);

namespace App\Infrastructure\Logger;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Logging für Bot-API-Kommunikation.
 * Kein Inhalt der Anfragen loggen (enthält Spielzustand mit Kartendaten).
 */
final class BotApiLogger
{
    public function __construct(
        #[WithMonologChannel('bot_api')]
        private readonly LoggerInterface $logger,
    ) {}

    public function anfrageSent(string $botId, string $spielId, string $tischplatzId): void
    {
        $this->logger->info('Spielzug-Anfrage an Bot gesendet', [
            'bot_id'       => $botId,
            'spiel_id'     => $spielId,
            'tischplatz'   => $tischplatzId,
        ]);
    }

    public function antwortErhalten(string $botId, string $spielId, float $antwortZeitMs): void
    {
        $this->logger->info('Bot-Antwort erhalten', [
            'bot_id'          => $botId,
            'spiel_id'        => $spielId,
            'antwort_zeit_ms' => round($antwortZeitMs, 2),
        ]);
    }

    public function zeitüberschreitung(string $botId, string $spielId, int $timeoutSekunden): void
    {
        $this->logger->warning('Bot-Timeout – zufällige legale Karte wird gewählt', [
            'bot_id'    => $botId,
            'spiel_id'  => $spielId,
            'timeout_s' => $timeoutSekunden,
        ]);
    }

    public function verbindungsFehler(string $botId, string $spielId, string $fehler): void
    {
        $this->logger->error('Bot-API nicht erreichbar', [
            'bot_id'   => $botId,
            'spiel_id' => $spielId,
            'fehler'   => $fehler,
        ]);
    }
}
