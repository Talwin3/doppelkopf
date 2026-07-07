<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\BotStaerke;
use App\Repository\GespielteKarteRepository;

/**
 * Stufe {@see BotStaerke::PROFI}: Kartenwahl per Perfect-Information Monte Carlo.
 *
 * Sammelt den Spielzustand aus der DB (Verlauf, Handgrößen, Kreuz-Damen, öffentlich
 * bekannte Teams) und übergibt ihn an die reine {@see ProfiHeuristik}-Engine.
 */
final class ProfiStrategie implements BotStrategie
{
    public function __construct(
        private readonly ProfiHeuristik $heuristik,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly OeffentlicheTeams $oeffentlicheTeams,
    ) {}

    public function staerke(): BotStaerke
    {
        return BotStaerke::PROFI;
    }

    public function waehleKarte(Spiel $spiel, SpielTeilnehmer $teilnehmer, array $erlaubte): Karte
    {
        $ordnung = $this->trumpfOrdnungFactory->fuerSpiel($spiel);

        $ereignisse           = [];
        $gespielteIds         = [];
        $offenerStich         = [];
        $gespieltProSitz      = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $kreuzDameGespieltVon = [];
        foreach ($this->gespielteKarteRepo->findAlleGespieltenKarten($spiel) as $gk) {
            $karte = $gk->alsKarte();
            $ereignisse[] = [
                'sitzplatz' => $gk->getSitzplatz(),
                'stichNr'   => $gk->getStichNr(),
                'position'  => $gk->getPositionImStich(),
                'karte'     => $karte,
            ];
            $gespielteIds[] = $gk->getKarteId();
            $gespieltProSitz[$gk->getSitzplatz()] = ($gespieltProSitz[$gk->getSitzplatz()] ?? 0) + 1;

            if (str_starts_with($gk->getKarteId(), 'KREUZ_DAME')) {
                $kreuzDameGespieltVon[] = $gk->getSitzplatz();
            }
            if ($gk->getStichNr() === $spiel->getAktuellerStichNr()) {
                $offenerStich[$gk->getSitzplatz()] = $karte;
            }
        }

        // Kartenuniversum = Vereinigung aller Starthände; Handgrößen der 3 anderen.
        $universum        = [];
        $startProSitz     = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($spiel->getTeilnehmer() as $t) {
            foreach ($t->getStartkartenIds() as $id) {
                $universum[$id] = Karte::vonId($id);
            }
            $startProSitz[$t->getSitzplatz()] = count($t->getStartkartenIds());
        }

        $eigenerSitz = $teilnehmer->getSitzplatz();
        $eigeneHand  = $teilnehmer->aktuelleHand($gespielteIds);
        $gedaechtnis = SpielGedaechtnis::ausHistorie($ereignisse, array_values($universum), $eigeneHand, $ordnung);

        $restHandGroessen = [];
        for ($sitz = 1; $sitz <= 4; $sitz++) {
            if ($sitz === $eigenerSitz) {
                continue;
            }
            $restHandGroessen[$sitz] = $startProSitz[$sitz] - $gespieltProSitz[$sitz];
        }

        $bekannteTeams = $this->oeffentlicheTeams->ermitteln($spiel, $eigenerSitz);
        $zweiteDulle   = (bool) ($spiel->getRegelEinstellungen()['zweite_dulle_sticht'] ?? false);

        return $this->heuristik->entscheide(
            $erlaubte,
            $eigeneHand,
            $eigenerSitz,
            $teilnehmer->getTeam(),
            $offenerStich,
            $restHandGroessen,
            $gedaechtnis->ungesehene(),
            $gedaechtnis,
            $spiel->getVariante(),
            $bekannteTeams,
            array_values(array_unique($kreuzDameGespieltVon)),
            $ordnung,
            $zweiteDulle,
        );
    }
}
