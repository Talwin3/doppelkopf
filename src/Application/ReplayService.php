<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Doppelkopf\Service\StichGewinner;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Entity\Spiel;
use App\Entity\User;
use App\Repository\SpielAnsageRepository;

/**
 * Bereitet ein beendetes Spiel als Stich-für-Stich-Replay auf.
 *
 * Liefert eine reine (entitätsfreie) Datenstruktur, die der Replay-Stimulus-
 * Controller im Browser durchsteppt: pro Stich die vier gespielten Karten in
 * Spielreihenfolge, den Stichgewinner samt Augensumme sowie die Ansagen mit
 * dem Stich, in dem sie fielen. Stichgewinner werden mit derselben Logik
 * ermittelt wie im laufenden Spiel ({@see StichGewinner} + {@see TrumpfOrdnungFactory}).
 */
final class ReplayService
{
    public function __construct(
        private readonly TrumpfOrdnungFactory $ordnungFactory,
        private readonly StichGewinner $stichGewinner,
        private readonly SpielAnsageRepository $ansageRepo,
    ) {}

    /**
     * @return array{
     *   meinSitzplatz: int,
     *   variante: string,
     *   spieler: array<int, array{sitzplatz: int, name: string, team: ?string, istBot: bool}>,
     *   stiche: array<int, array{nr: int, leadSeat: int, winnerSeat: int, augen: int, karten: array<int, array{seat: int, karteId: string, augen: int}>}>,
     *   ansagen: array<int, array{seat: int, typ: string, trick: int}>,
     *   kartenIds: string[]
     * }
     */
    public function baueDaten(Spiel $spiel, User $betrachter): array
    {
        $ordnung           = $this->ordnungFactory->fuerSpiel($spiel);
        $zweiteDulleSticht = (bool) ($spiel->getRegelEinstellungen()['zweite_dulle_sticht'] ?? false);

        // Karten nach Stich gruppieren, innerhalb des Stichs nach Spielposition.
        /** @var array<int, array<int, \App\Entity\GespielteKarte>> $proStich */
        $proStich = [];
        foreach ($spiel->getGespielteKarten() as $gk) {
            $proStich[$gk->getStichNr()][$gk->getPositionImStich()] = $gk;
        }
        ksort($proStich);

        $stiche    = [];
        $kartenIds = [];
        foreach ($proStich as $nr => $karten) {
            ksort($karten);

            $kartenListe = [];
            $augenSumme  = 0;
            $leadSeat    = 0;
            $kartenFuerGewinner = []; // sitzplatz => Karte, in Spielreihenfolge
            foreach ($karten as $gk) {
                $karte = $gk->alsKarte();
                $seat  = $gk->getSitzplatz();
                if ($leadSeat === 0) {
                    $leadSeat = $seat;
                }
                $kartenFuerGewinner[$seat] = $karte;
                $augenSumme += $karte->augen();
                $kartenListe[] = ['seat' => $seat, 'karteId' => $gk->getKarteId(), 'augen' => $karte->augen()];
                $kartenIds[$gk->getKarteId()] = true;
            }

            // Gewinner nur bei vollständigem Stich (4 Karten) bestimmen.
            $winnerSeat = count($kartenFuerGewinner) === 4
                ? $this->stichGewinner->bestimme($kartenFuerGewinner, $ordnung, $zweiteDulleSticht)
                : 0;

            $stiche[] = [
                'nr'         => $nr,
                'leadSeat'   => $leadSeat,
                'winnerSeat' => $winnerSeat,
                'augen'      => $augenSumme,
                'karten'     => $kartenListe,
            ];
        }

        $spieler = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            $spieler[$t->getSitzplatz()] = [
                'sitzplatz' => $t->getSitzplatz(),
                'name'      => $t->getAnzeigeName(),
                'team'      => $t->getTeam()?->value,
                'istBot'    => $t->isIstBot(),
            ];
        }
        ksort($spieler);

        $ansagen = [];
        foreach ($this->ansageRepo->findBy(['spiel' => $spiel], ['gemachtAm' => 'ASC']) as $a) {
            $ansagen[] = [
                'seat'  => $a->getSitzplatz(),
                'typ'   => $a->getAnsageTyp()->value,
                'trick' => $a->getStichNrBeiAnsage(),
            ];
        }

        $meinSitzplatz = 0;
        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->getUser()?->getId() == $betrachter->getId()) {
                $meinSitzplatz = $t->getSitzplatz();
                break;
            }
        }

        return [
            'meinSitzplatz' => $meinSitzplatz,
            'variante'      => $spiel->getVariante()?->value ?? 'NORMALSPIEL',
            'spieler'       => array_values($spieler),
            'stiche'        => $stiche,
            'ansagen'       => $ansagen,
            'kartenIds'     => array_keys($kartenIds),
        ];
    }
}
