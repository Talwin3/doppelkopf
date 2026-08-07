<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\BotStaerke;

/**
 * Stufe {@see BotStaerke::PROFI}: Kartenwahl per Perfect-Information Monte Carlo.
 *
 * Den Spielzustand sammelt {@see ProfiSpielstand} – dieselbe Quelle, aus der auch die
 * Ansage-Entscheidung ({@see BotAnsage}) schöpft. Hier bleibt nur die Übergabe an die reine
 * {@see ProfiHeuristik}-Engine.
 */
final class ProfiStrategie implements BotStrategie
{
    public function __construct(
        private readonly ProfiHeuristik $heuristik,
        private readonly ProfiSpielstand $spielstand,
    ) {}

    public function staerke(): BotStaerke
    {
        return BotStaerke::PROFI;
    }

    public function waehleKarte(Spiel $spiel, SpielTeilnehmer $teilnehmer, array $erlaubte): Karte
    {
        $z = $this->spielstand->sammeln($spiel, $teilnehmer);

        return $this->heuristik->entscheide(
            erlaubte: $erlaubte,
            eigeneHand: $z->eigeneHand,
            eigenerSitz: $z->eigenerSitz,
            eigenesTeam: $z->eigenesTeam,
            offenerStich: $z->offenerStich,
            restHandGroessen: $z->restHandGroessen,
            ungesehene: $z->gedaechtnis->ungesehene(),
            gedaechtnis: $z->gedaechtnis,
            variante: $z->variante,
            bekannteTeams: $z->bekannteTeams,
            kreuzDameGespieltVon: $z->kreuzDameGespieltVon,
            ordnung: $z->ordnung,
            zweiteDulle: $z->zweiteDulle,
            bisherigeStiche: $z->bisherigeStiche,
            ansagen: $z->ansagen,
            solistSitzplatz: $z->solistSitzplatz,
        );
    }
}
