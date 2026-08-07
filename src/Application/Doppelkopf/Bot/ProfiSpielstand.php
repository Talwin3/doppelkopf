<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\Team;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielAnsageRepository;

/**
 * Sammelt den Spielzustand für die PIMC-Engine aus der DB.
 *
 * Eigene Klasse, weil ihn zwei Aufrufer brauchen: die Kartenwahl ({@see ProfiStrategie})
 * und die Ansage-Entscheidung per Rollout ({@see BotAnsage}). Beide müssen dieselbe Sicht
 * auf das Spiel haben – sonst würde derselbe Bot seine Karten nach anderen Fakten wählen,
 * als er seine Ansage begründet.
 */
final class ProfiSpielstand
{
    public function __construct(
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielAnsageRepository $ansageRepo,
        private readonly OeffentlicheTeams $oeffentlicheTeams,
    ) {}

    public function sammeln(Spiel $spiel, SpielTeilnehmer $teilnehmer): ProfiZustand
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
        $universum    = [];
        $startProSitz = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($spiel->getTeilnehmer() as $t) {
            foreach ($t->getStartkartenIds() as $id) {
                $universum[$id] = Karte::vonId($id);
            }
            $startProSitz[$t->getSitzplatz()] = count($t->getStartkartenIds());
        }

        $eigenerSitz = $teilnehmer->getSitzplatz();
        $eigeneHand  = $teilnehmer->aktuelleHand($gespielteIds);

        $restHandGroessen = [];
        for ($sitz = 1; $sitz <= 4; $sitz++) {
            if ($sitz === $eigenerSitz) {
                continue;
            }
            $restHandGroessen[$sitz] = $startProSitz[$sitz] - $gespieltProSitz[$sitz];
        }

        $ansagen = [];
        foreach ($this->ansageRepo->findFuerSpiel($spiel) as $a) {
            $ansagen[] = ['sitzplatz' => $a->getSitzplatz(), 'typ' => $a->getAnsageTyp()];
        }

        return new ProfiZustand(
            eigenerSitz: $eigenerSitz,
            eigenesTeam: $teilnehmer->getTeam(),
            eigeneHand: $eigeneHand,
            offenerStich: $offenerStich,
            restHandGroessen: $restHandGroessen,
            gedaechtnis: SpielGedaechtnis::ausHistorie($ereignisse, array_values($universum), $eigeneHand, $ordnung),
            ordnung: $ordnung,
            variante: $spiel->getVariante(),
            bekannteTeams: $this->oeffentlicheTeams->ermitteln($spiel, $eigenerSitz),
            kreuzDameGespieltVon: array_values(array_unique($kreuzDameGespieltVon)),
            zweiteDulle: (bool) ($spiel->getRegelEinstellungen()['zweite_dulle_sticht'] ?? false),
            // Die Wertungsschwellen (121, keine 90 …) gelten fürs ganze Spiel, die Engine
            // bewertet aber nur den Rest – die gelaufenen Stiche und Ansagen gehören dazu.
            bisherigeStiche: $spiel->vollstaendigeStiche(),
            ansagen: $ansagen,
            solistSitzplatz: $this->solistSitzplatz($spiel),
        );
    }

    /**
     * Der Solist zählt in der Abrechnung dreifach – für die Bewertung zählt nur, dass der
     * {@see \App\Domain\Doppelkopf\Service\WertungsRechner} dann die Sonderpunkte
     * (Fuchs/Karlchen/Doppelkopf) und „Gegen die Alten" weglässt. Gleiche Ermittlung wie in
     * {@see \App\Application\Doppelkopf\SpielAbschlussService}: die ungeklärte Hochzeit
     * rechnet ebenfalls wie ein Solo ab.
     */
    private function solistSitzplatz(Spiel $spiel): ?int
    {
        $variante = $spiel->getVariante();
        $istSolo  = $spiel->isHochzeitAlsSolo()
            || ($variante !== null && str_starts_with($variante->value, 'SOLO_'));

        if (!$istSolo) {
            return null;
        }

        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->getTeam() === Team::RE) {
                return $t->getSitzplatz();
            }
        }

        return null;
    }
}
