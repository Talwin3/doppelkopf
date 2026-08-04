<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Application\Doppelkopf\AnsageService;
use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielAnsage;
use App\Entity\SpielTeilnehmer;
use App\Enum\AnsageTyp;
use App\Repository\GespielteKarteRepository;
use App\Repository\SpielAnsageRepository;
use Psr\Log\LoggerInterface;

/**
 * Prüft vor dem Zug eines Bots, ob er ansagen sollte (Re/Contra und darauf aufbauend die
 * Absagen), und macht die Ansage regelkonform. Kapselt die Entscheidung ({@see AnsageHeuristik}) und den
 * Aufruf des {@see AnsageService}, damit der {@see \App\Application\Doppelkopf\BotZugService}
 * schlank bleibt.
 */
final class BotAnsage
{
    public function __construct(
        private readonly AnsageHeuristik $ansageHeuristik,
        private readonly AnsageService $ansageService,
        private readonly TrumpfOrdnungFactory $trumpfOrdnungFactory,
        private readonly GespielteKarteRepository $gespielteKarteRepo,
        private readonly SpielAnsageRepository $ansageRepo,
        private readonly LoggerInterface $logger,
    ) {}

    public function pruefen(Spiel $spiel, SpielTeilnehmer $teilnehmer): void
    {
        if ($teilnehmer->getTeam() === null) {
            return;
        }

        $ordnung = $this->trumpfOrdnungFactory->fuerSpiel($spiel);
        $gespielteIds = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $hand = $teilnehmer->aktuelleHand($gespielteIds);

        $typ = $this->ansageHeuristik->willAnsagen(
            $teilnehmer->getBotStaerke(),
            $hand,
            $teilnehmer->getTeam(),
            $ordnung,
            null,
            $this->teamAnsagen($spiel, $teilnehmer),
            fn(AnsageTyp $typ): int => $this->ansageService->mindestKarten($spiel, $typ),
            // Bewertet wird die ausgeteilte Hand: Wer bis zum letztmöglichen Zeitpunkt
            // wartet, hat schon eine Karte gespielt – zählte nur das Restblatt, fielen
            // Ansagen aus einem sachfremden Grund weg (im Benchmark 6 %).
            array_map(static fn(string $id) => Karte::vonId($id), $teilnehmer->getStartkartenIds()),
        );
        if ($typ === null) {
            return;
        }

        try {
            $this->ansageService->machenAlsBot($spiel, $teilnehmer->getSitzplatz(), $typ);
        } catch (\Throwable $e) {
            $this->logger->warning('BotAnsage: Ansage fehlgeschlagen.', [
                'spiel_id'  => (string) $spiel->getId(),
                'sitzplatz' => $teilnehmer->getSitzplatz(),
                'typ'       => $typ->value,
                'fehler'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Was das eigene Team bereits angesagt hat – die Absagen bauen aufeinander auf, der
     * Bot muss also wissen, auf welcher Stufe sein Team steht.
     *
     * @return AnsageTyp[]
     */
    private function teamAnsagen(Spiel $spiel, SpielTeilnehmer $teilnehmer): array
    {
        $sitzplaetze = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            if ($t->getTeam() === $teilnehmer->getTeam()) {
                $sitzplaetze[] = $t->getSitzplatz();
            }
        }

        if ($sitzplaetze === []) {
            return [];
        }

        return array_map(
            fn(SpielAnsage $a) => $a->getAnsageTyp(),
            $this->ansageRepo->findFuerTeam($spiel, $sitzplaetze),
        );
    }
}
