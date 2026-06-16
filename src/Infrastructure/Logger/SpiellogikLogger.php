<?php

declare(strict_types=1);

namespace App\Infrastructure\Logger;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Strukturiertes Logging für Spiellogik-Ereignisse.
 * Niemals Kartenhände anderer Spieler loggen (Anti-Cheat + DSGVO).
 */
final class SpiellogikLogger
{
    public function __construct(
        #[WithMonologChannel('spiellogik')]
        private readonly LoggerInterface $logger,
    ) {}

    public function spielzugAusgefuehrt(string $spielId, string $username, string $karteCode): void
    {
        $this->logger->info('Spielzug ausgeführt', [
            'spiel_id' => $spielId,
            'username' => $username,
            'karte'    => $karteCode,
        ]);
    }

    public function illegalesZugVersucht(string $spielId, string $username, string $karteCode, string $grund): void
    {
        $this->logger->warning('Illegaler Spielzugversuch', [
            'spiel_id' => $spielId,
            'username' => $username,
            'karte'    => $karteCode,
            'grund'    => $grund,
        ]);
    }

    public function spielGestartet(string $spielId, string $spieltischId, string $variante): void
    {
        $this->logger->info('Spiel gestartet', [
            'spiel_id'    => $spielId,
            'spieltisch'  => $spieltischId,
            'variante'    => $variante,
        ]);
    }

    public function spielBeendet(string $spielId, string $gewinnerPartei, int $augenRe, int $augenContra): void
    {
        $this->logger->info('Spiel beendet', [
            'spiel_id'      => $spielId,
            'gewinner'      => $gewinnerPartei,
            'augen_re'      => $augenRe,
            'augen_contra'  => $augenContra,
        ]);
    }

    public function ansageGemacht(string $spielId, string $username, string $ansageTyp): void
    {
        $this->logger->info('Ansage gemacht', [
            'spiel_id' => $spielId,
            'username' => $username,
            'ansage'   => $ansageTyp,
        ]);
    }

    public function botUebernimmt(string $spielId, string $username, string $grund): void
    {
        $this->logger->warning('Bot übernimmt für Spieler', [
            'spiel_id' => $spielId,
            'username' => $username,
            'grund'    => $grund,
        ]);
    }

    public function exceptionGefangen(\Throwable $e, array $kontext = []): void
    {
        $this->logger->error($e->getMessage(), array_merge($kontext, [
            'exception' => $e::class,
            'datei'     => $e->getFile(),
            'zeile'     => $e->getLine(),
        ]));
    }
}
