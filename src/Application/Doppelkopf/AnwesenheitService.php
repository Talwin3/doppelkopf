<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Entity\Tisch;
use App\Entity\User;
use App\Repository\SpielRepository;
use App\Repository\SpielTeilnehmerRepository;

/**
 * Lebenszeichen eines Spielers, der gerade keinen eigenen Zug hat. Normalerweise
 * beendet erst sein nächster Zug eine Bot-Vertretung ({@see TischProtokollService::spielerZurueck()}).
 * Wer aber erst zurückkommt, nachdem er seine letzten Karten verpasst hat, hätte keinen
 * Zug mehr und würde beim Spielende als abwesend seinen Platz verlieren
 * ({@see SpielAbschlussService}). Deshalb zählen auch das Öffnen der Tischseite und
 * eine Chat-Nachricht als „wieder da".
 *
 * Bewusst NICHT als Lebenszeichen: das automatische Nachladen des Spielzustands nach
 * Mercure-Events. Das läuft auch, wenn der Spieler den Browser offen lässt und weggeht.
 */
final class AnwesenheitService
{
    public function __construct(
        private readonly SpielRepository $spielRepo,
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly TischProtokollService $protokoll,
    ) {}

    public function lebenszeichen(Tisch $tisch, User $user): void
    {
        $spiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
        if ($spiel === null) {
            return;
        }

        $teilnehmer = $this->teilnehmerRepo->findBySpielAndUser($spiel, $user);
        if ($teilnehmer !== null) {
            $this->protokoll->spielerZurueck($teilnehmer);
        }
    }
}
