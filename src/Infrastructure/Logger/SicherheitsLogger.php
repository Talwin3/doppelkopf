<?php

declare(strict_types=1);

namespace App\Infrastructure\Logger;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Strukturiertes Logging für Sicherheitsereignisse.
 * Regel: Niemals E-Mail-Adressen loggen – nur Benutzernamen oder interne IDs.
 */
final class SicherheitsLogger
{
    public function __construct(
        #[WithMonologChannel('sicherheit')]
        private readonly LoggerInterface $logger,
    ) {}

    public function registrierungErfolgreich(string $username): void
    {
        $this->logger->info('Neue Registrierung', ['username' => $username]);
    }

    public function emailVerifiziert(string $username): void
    {
        $this->logger->info('E-Mail verifiziert', ['username' => $username]);
    }

    public function verifikationFehlgeschlagen(string $username, string $grund): void
    {
        $this->logger->warning('E-Mail-Verifikation fehlgeschlagen', [
            'username' => $username,
            'grund'    => $grund,
        ]);
    }

    public function loginErfolgreich(string $username): void
    {
        $this->logger->info('Login erfolgreich', ['username' => $username]);
    }

    public function loginFehlgeschlagen(string $emailOderUsername): void
    {
        // Keine E-Mail in Logs – nur die ersten 3 Zeichen als Hinweis
        $this->logger->warning('Login fehlgeschlagen', [
            'identifier_prefix' => substr($emailOderUsername, 0, 3) . '***',
        ]);
    }

    public function passwortResetAngefordert(string $username): void
    {
        $this->logger->info('Passwort-Reset angefordert', ['username' => $username]);
    }

    public function zugriffVerweigert(string $username, string $ressource): void
    {
        $this->logger->warning('Zugriff verweigert', [
            'username'  => $username,
            'ressource' => $ressource,
        ]);
    }

    public function tischBeitrittVerweigert(string $username, string $spieltischId, string $grund): void
    {
        $this->logger->info('Tischbeitritt verweigert', [
            'username'     => $username,
            'spieltisch'   => $spieltischId,
            'grund'        => $grund,
        ]);
    }
}
