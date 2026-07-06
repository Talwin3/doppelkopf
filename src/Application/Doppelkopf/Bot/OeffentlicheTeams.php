<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\Service\TeamSichtbarkeit;
use App\Entity\Spiel;
use App\Enum\Team;
use App\Enum\VorbehaltTyp;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielAnsageRepository;

/**
 * Liefert für einen Bot die **öffentlich bekannten** Team-Zuordnungen, damit er
 * keine verdeckte Partnerschaft ausnutzt. Sammelt die nötigen Fakten aus der DB
 * (gespielte Kreuz-Damen, Ansagen, Hochzeit-Status) und delegiert die Regel an
 * die reine {@see TeamSichtbarkeit}.
 */
final class OeffentlicheTeams
{
    public function __construct(
        private readonly SpielAnsageRepository $ansageRepo,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
    ) {}

    /**
     * @return array<int, ?Team> Sitzplatz → bekanntes Team (null = für diesen Bot noch verdeckt).
     */
    public function ermitteln(Spiel $spiel, int $eigenerSitzplatz): array
    {
        $teamsAlle = [];
        $hochzeitspielerSitze = [];
        for ($sitz = 1; $sitz <= 4; $sitz++) {
            $t = $spiel->getTeilnehmerBySitzplatz($sitz);
            $teamsAlle[$sitz] = $t?->getTeam();
            if ($t !== null && $t->getVorbehaltTyp() === VorbehaltTyp::HOCHZEIT) {
                $hochzeitspielerSitze[] = $sitz;
            }
        }

        $kreuzDamenGespieltSitze = [];
        foreach ($this->gespielteKarteRepo->findAlleGespieltenKarten($spiel) as $gk) {
            if (str_starts_with($gk->getKarteId(), 'KREUZ_DAME')) {
                $kreuzDamenGespieltSitze[] = $gk->getSitzplatz();
            }
        }

        $angesagtSitze = [];
        foreach ($this->ansageRepo->findFuerSpiel($spiel) as $ansage) {
            $angesagtSitze[] = $ansage->getSitzplatz();
        }

        return TeamSichtbarkeit::bekannteTeams(
            $teamsAlle,
            $spiel->getVariante(),
            $spiel->isHochzeitAufgeloest(),
            $hochzeitspielerSitze,
            array_values(array_unique($kreuzDamenGespieltSitze)),
            array_values(array_unique($angesagtSitze)),
            $eigenerSitzplatz,
        );
    }
}
