<?php

declare(strict_types=1);

namespace App\Infrastructure\Logger\Processor;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Hängt an jeden Log-Eintrag eine kurze Request-ID an,
 * damit alle Zeilen einer HTTP-Anfrage quer über alle Kanäle zusammengehören.
 */
#[AsMonologProcessor]
final class AnfragenIdProcessor
{
    private readonly string $anfragenId;

    public function __construct()
    {
        $this->anfragenId = substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: array_merge($record->extra, [
            'anfragen_id' => $this->anfragenId,
        ]));
    }
}
