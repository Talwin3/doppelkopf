<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Application\Doppelkopf\AnsageService;
use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Service\StichStatistik;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory;
use App\Domain\Doppelkopf\ValueObject\AnsageAussichten;
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielAnsage;
use App\Entity\SpielTeilnehmer;
use App\Enum\AnsageTyp;
use App\Enum\BotStaerke;
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
        private readonly StichStatistik $stichStatistik,
        private readonly ProfiSpielstand $profiSpielstand,
        private readonly ProfiHeuristik $profiHeuristik,
        private readonly LoggerInterface $logger,
    ) {}

    public function pruefen(Spiel $spiel, SpielTeilnehmer $teilnehmer): void
    {
        if ($teilnehmer->getTeam() === null) {
            return;
        }

        $ordnung   = $this->trumpfOrdnungFactory->fuerSpiel($spiel);
        $staerke   = $teilnehmer->getBotStaerke();
        $schwellen = AnsageSchwellen::fuer($staerke);
        $gespielteIds = $this->gespielteKarteRepo->findGespielteKartenIds($spiel, $teilnehmer->getSitzplatz());
        $hand = $teilnehmer->aktuelleHand($gespielteIds);

        $typ = $this->ansageHeuristik->willAnsagen(
            $staerke,
            $hand,
            $teilnehmer->getTeam(),
            $ordnung,
            $schwellen,
            $this->teamAnsagen($spiel, $teilnehmer),
            fn(AnsageTyp $typ): int => $this->ansageService->mindestKarten($spiel, $typ),
            // Die ausgeteilte Hand als Alternative zum Restblatt; welche bewertet wird,
            // entscheiden die Schwellen ({@see AnsageSchwellen::$bewerteRestblatt}).
            array_map(static fn(string $id) => Karte::vonId($id), $teilnehmer->getStartkartenIds()),
            // Essener: „bis zur Ansage erzielte Augenzahl plus Summe der Erwartungswerte".
            $this->erspielteTeamAugen($spiel, $teilnehmer, $ordnung),
            $this->aussichtenGeber($spiel, $teilnehmer, $schwellen),
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
     * Liefert den Rollout-Schätzer, sofern diese Stufe ihn nutzt – sonst null, dann bleibt
     * es bei der Blattbewertung.
     *
     * Bewusst ein Callback: Der Zustand kostet mehrere DB-Abfragen und die Rollouts spürbar
     * Rechenzeit. Beides fällt erst an, wenn Frist und Voraussetzungskette eine Ansage
     * überhaupt zulassen – also höchstens einmal je Ansagetyp und Blatt statt vor jedem Zug.
     *
     * @return ?callable(AnsageTyp[]): AnsageAussichten
     */
    private function aussichtenGeber(Spiel $spiel, SpielTeilnehmer $teilnehmer, AnsageSchwellen $schwellen): ?callable
    {
        $rollout = $schwellen->rollout;
        if ($rollout === null || $teilnehmer->getBotStaerke() !== BotStaerke::PROFI) {
            return null;
        }

        return function (array $kandidaten) use ($spiel, $teilnehmer, $rollout): AnsageAussichten {
            $z = $this->profiSpielstand->sammeln($spiel, $teilnehmer);

            return $this->profiHeuristik->aussichten(
                kandidaten: $kandidaten,
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
                rollouts: $rollout->welten,
            );
        };
    }

    /**
     * Augen, die die Partei dieses Teilnehmers in den abgeschlossenen Stichen bereits geholt
     * hat. Liegt offen auf dem Tisch (die Stich-Stapel), ist also kein verdecktes Wissen.
     */
    private function erspielteTeamAugen(Spiel $spiel, SpielTeilnehmer $teilnehmer, TrumpfOrdnung $ordnung): int
    {
        $team = $teilnehmer->getTeam();
        if ($team === null) {
            return 0;
        }

        $teams = [];
        foreach ($spiel->getTeilnehmer() as $t) {
            $teams[$t->getSitzplatz()] = $t->getTeam();
        }

        return $this->stichStatistik->augenFuerTeam(
            $spiel->vollstaendigeStiche(),
            $teams,
            $team,
            $ordnung,
            (bool) $spiel->getRegelEinstellung('zweite_dulle_sticht', false),
        );
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
