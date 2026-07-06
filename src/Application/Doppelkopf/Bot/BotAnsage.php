<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Application\Doppelkopf\AnsageService;
use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Repository\GespielteKarteRepository;
use Psr\Log\LoggerInterface;

/**
 * Prüft vor dem Zug eines Bots, ob er "Re"/"Contra" ansagen sollte, und macht die
 * Ansage regelkonform. Kapselt die Entscheidung ({@see AnsageHeuristik}) und den
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

        $typ = $this->ansageHeuristik->willAnsagen($teilnehmer->getBotStaerke(), $hand, $teilnehmer->getTeam(), $ordnung);
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
}
