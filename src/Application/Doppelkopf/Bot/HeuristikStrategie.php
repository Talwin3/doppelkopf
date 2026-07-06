<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\BotStaerke;
use App\Repository\GespielteKarteRepository;

/**
 * Stufe {@see BotStaerke::ANFAENGER}: regelbasierte Kartenwahl.
 *
 * Sammelt den aktuellen Stich und die Team-Zuordnung und delegiert die
 * eigentliche Entscheidung an die reine {@see BotHeuristik}-Engine.
 */
final class HeuristikStrategie implements BotStrategie
{
    public function __construct(
        private readonly BotHeuristik $botHeuristik,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly OeffentlicheTeams $oeffentlicheTeams,
    ) {}

    public function staerke(): BotStaerke
    {
        return BotStaerke::ANFAENGER;
    }

    public function waehleKarte(Spiel $spiel, SpielTeilnehmer $teilnehmer, array $erlaubte): Karte
    {
        $ordnung = $this->trumpfOrdnungFactory->fuerSpiel($spiel);

        // Aktuellen Stich als Sitzplatz → Karte in Spielreihenfolge aufbauen.
        $stich = [];
        foreach ($this->gespielteKarteRepo->findAktuellerStich($spiel, $spiel->getAktuellerStichNr()) as $gk) {
            $stich[$gk->getSitzplatz()] = $gk->alsKarte();
        }

        // Nur öffentlich bekannte Teams (kein Ausnutzen verdeckter Partnerschaft).
        $teams = $this->oeffentlicheTeams->ermitteln($spiel, $teilnehmer->getSitzplatz());

        return $this->botHeuristik->entscheide($erlaubte, $stich, $teilnehmer->getTeam(), $teams, $ordnung);
    }
}
