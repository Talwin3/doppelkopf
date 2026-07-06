<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\BotStaerke;
use App\Repository\GespielteKarteRepository;

/**
 * Stufe {@see BotStaerke::FORTGESCHRITTEN}: Kartenwahl mit Kartengedächtnis.
 *
 * Sammelt den kompletten Spielverlauf, rekonstruiert das Kartenuniversum aus den
 * Starthänden aller vier Teilnehmer und delegiert an die reine
 * {@see FortgeschritteneHeuristik}-Engine (samt {@see SpielGedaechtnis}).
 */
final class FortgeschritteneStrategie implements BotStrategie
{
    public function __construct(
        private readonly FortgeschritteneHeuristik $heuristik,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
    ) {}

    public function staerke(): BotStaerke
    {
        return BotStaerke::FORTGESCHRITTEN;
    }

    public function waehleKarte(Spiel $spiel, SpielTeilnehmer $teilnehmer, array $erlaubte): Karte
    {
        $ordnung = $this->trumpfOrdnungFactory->fuerSpiel($spiel);

        // Kompletten Verlauf laden und als geordnete Ereignisliste aufbereiten.
        $ereignisse   = [];
        $gespielteIds = [];
        $aktuellerStich = [];
        foreach ($this->gespielteKarteRepo->findAlleGespieltenKarten($spiel) as $gk) {
            $karte = $gk->alsKarte();
            $ereignisse[] = [
                'sitzplatz' => $gk->getSitzplatz(),
                'stichNr'   => $gk->getStichNr(),
                'position'  => $gk->getPositionImStich(),
                'karte'     => $karte,
            ];
            $gespielteIds[] = $gk->getKarteId();

            if ($gk->getStichNr() === $spiel->getAktuellerStichNr()) {
                $aktuellerStich[$gk->getSitzplatz()] = $karte;
            }
        }

        // Kartenuniversum dieses Spiels = Vereinigung aller Starthände.
        $universum = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            foreach ($t->getStartkartenIds() as $id) {
                $universum[$id] = Karte::vonId($id);
            }
        }

        $eigeneHand  = $teilnehmer->aktuelleHand($gespielteIds);
        $gedaechtnis = SpielGedaechtnis::ausHistorie($ereignisse, array_values($universum), $eigeneHand, $ordnung);

        // Teams aller vier Sitzplätze (null bei noch ungelöster Hochzeit).
        $teams = [];
        for ($sitz = 1; $sitz <= 4; $sitz++) {
            $teams[$sitz] = $spiel->getTeilnehmerBySitzplatz($sitz)?->getTeam();
        }

        return $this->heuristik->entscheide(
            $erlaubte,
            $aktuellerStich,
            $teilnehmer->getTeam(),
            $teams,
            $ordnung,
            $gedaechtnis,
        );
    }
}
