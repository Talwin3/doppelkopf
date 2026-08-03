<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;

/**
 * Ein Spieler im Self-Play-Benchmark. Datenbankfreies Gegenstück zu
 * {@see \App\Application\Doppelkopf\Bot\BotStrategie}.
 */
interface SimulierteStrategie
{
    /** Anzeigename für die Auswertung (z. B. "profi(r=300)"). */
    public function name(): string;

    /** @param Karte[] $erlaubte Spielbare Karten (mind. eine) */
    public function waehleKarte(Partie $partie, int $sitzplatz, array $erlaubte): Karte;

    /** Ansage vor dem eigenen Zug; null = keine. Die Zulässigkeit prüft der Simulator. */
    public function willAnsagen(Partie $partie, int $sitzplatz): ?AnsageTyp;
}
