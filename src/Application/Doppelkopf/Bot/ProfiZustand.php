<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\SpielVariante;
use App\Enum\Team;

/**
 * Der Spielzustand, den die {@see \App\Domain\Doppelkopf\Service\ProfiHeuristik} braucht –
 * einmal aus der DB gesammelt von {@see ProfiSpielstand}.
 *
 * Enthält ausschließlich **öffentlich sichtbares** Wissen: der Verlauf liegt auf dem Tisch,
 * die Teams kommen aus {@see OeffentlicheTeams} (verdeckte Partnerschaft bleibt null). Nie
 * ein Blick in fremde Hände – die verteilt die Engine selbst per Determinisierung.
 */
final readonly class ProfiZustand
{
    /**
     * @param Karte[]            $eigeneHand
     * @param array<int, Karte>  $offenerStich         Sitzplatz → Karte des laufenden Stichs
     * @param array<int, int>    $restHandGroessen     Sitzplatz (nur die 3 anderen) → Handkarten
     * @param array<int, ?Team>  $bekannteTeams
     * @param list<int>          $kreuzDameGespieltVon
     * @param list<list<array{sitzplatz: int, karte: Karte}>> $bisherigeStiche
     * @param list<array{sitzplatz: int, typ: AnsageTyp}>     $ansagen
     */
    public function __construct(
        public int $eigenerSitz,
        public ?Team $eigenesTeam,
        public array $eigeneHand,
        public array $offenerStich,
        public array $restHandGroessen,
        public SpielGedaechtnis $gedaechtnis,
        public TrumpfOrdnung $ordnung,
        public ?SpielVariante $variante,
        public array $bekannteTeams,
        public array $kreuzDameGespieltVon,
        public bool $zweiteDulle,
        public array $bisherigeStiche,
        public array $ansagen,
        public ?int $solistSitzplatz,
    ) {}
}
