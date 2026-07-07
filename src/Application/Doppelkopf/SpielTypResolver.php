<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielStatus;
use App\Enum\SpielVariante;
use App\Enum\Team;
use App\Enum\VorbehaltTyp;
use App\Infrastructure\Mercure\SpielMercurePublisher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wertet alle Vorbehalts-Deklarationen aus und bestimmt den endgültigen Spieltyp.
 *
 * Priorität (DDV-Standard):
 * 1. Solo — bei mehreren: Vorhand (Sitzplatz 1) zuerst, dann im Uhrzeigersinn
 * 2. Armut — bei mehreren: näher an Vorhand hat Vorrang
 * 3. Hochzeit — nur wenn kein Solo/Armut angemeldet
 * 4. Normalspiel (Kreuz-Dame-Regel)
 */
final class SpielTypResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpielMercurePublisher $mercurePublisher,
        private readonly ArmutService $armutService,
        private readonly TischProtokollService $protokoll,
        private readonly \App\Domain\Doppelkopf\Service\TrumpfOrdnungFactory $trumpfOrdnungFactory,
    ) {}

    /**
     * Löst die Vorbehaltsrunde auf — setzt SpielVariante, Teams, Status=LAUFEND.
     * Wird aufgerufen sobald alle 4 Spieler deklariert haben.
     */
    public function aufloesen(Spiel $spiel): void
    {
        /** @var SpielTeilnehmer[] $teilnehmer */
        $teilnehmer = $spiel->getTeilnehmer()->toArray();
        usort($teilnehmer, fn(SpielTeilnehmer $a, SpielTeilnehmer $b)
            => $a->getSitzplatz() <=> $b->getSitzplatz());

        // 1. Solo-Deklarationen prüfen (Priorität: Sitzplatz 1 → 4)
        $solisten = array_filter($teilnehmer, fn(SpielTeilnehmer $t)
            => $t->getVorbehaltTyp() === VorbehaltTyp::SOLO);

        if (!empty($solisten)) {
            $solist = array_values($solisten)[0]; // Niedrigster Sitzplatz = Vorhand-Priorität
            $this->aufloesenAlsSolo($spiel, $solist, $teilnehmer);
            return;
        }

        // 2. Armut-Deklarationen prüfen
        $regelwerk = $spiel->getRegelEinstellungen();
        if (!empty($regelwerk['armut'])) {
            $arme = array_filter($teilnehmer, fn(SpielTeilnehmer $t)
                => $t->getVorbehaltTyp() === VorbehaltTyp::ARMUT);

            if (!empty($arme)) {
                $armutSpieler = array_values($arme)[0]; // Niedrigster Sitzplatz = Vorhand-Priorität
                $this->armutService->anfrageStarten($spiel, $armutSpieler);
                return;
            }
        }

        // 3. Hochzeit-Deklarationen prüfen
        $hochzeiter = array_filter($teilnehmer, fn(SpielTeilnehmer $t)
            => $t->getVorbehaltTyp() === VorbehaltTyp::HOCHZEIT);

        if (!empty($hochzeiter)) {
            $hochzeitspieler = array_values($hochzeiter)[0];
            $this->aufloesenAlsHochzeit($spiel, $hochzeitspieler, $teilnehmer);
            return;
        }

        // 3. Normalspiel (Kreuz-Dame-Regel)
        $this->aufloesenAlsNormalspiel($spiel, $teilnehmer);
    }

    /** @param SpielTeilnehmer[] $alleTeilnehmer */
    private function aufloesenAlsSolo(Spiel $spiel, SpielTeilnehmer $solist, array $alleTeilnehmer): void
    {
        $variante = $solist->getVorbehaltSoloVariante()
            ?? throw new \LogicException('Solo-Deklaration ohne Variante.');

        $spiel->setVariante($variante);
        $this->protokoll->ereignis(
            $spiel->getTisch(),
            sprintf('%s spielt ein %s.', $solist->getAnzeigeName(), $variante->label()),
        );

        foreach ($alleTeilnehmer as $t) {
            $t->setTeam($t === $solist ? Team::RE : Team::KONTRA);
        }

        $regelwerk = $spiel->getRegelEinstellungen();
        $solistKommtRaus = $regelwerk['solist_kommt_raus'] ?? true;

        if ($solistKommtRaus) {
            $spiel->setAktuellerSpielerSitzplatz($solist->getSitzplatz());
        } else {
            $spiel->setAktuellerSpielerSitzplatz(1);
        }

        $this->spielStarten($spiel);
    }

    /** @param SpielTeilnehmer[] $alleTeilnehmer */
    private function aufloesenAlsHochzeit(Spiel $spiel, SpielTeilnehmer $hochzeitspieler, array $alleTeilnehmer): void
    {
        $spiel->setVariante(SpielVariante::HOCHZEIT);
        $this->protokoll->ereignis(
            $spiel->getTisch(),
            sprintf('%s spielt eine Hochzeit – der Partner wird im ersten Fremdstich bestimmt.', $hochzeitspieler->getAnzeigeName()),
        );

        // Hochzeit: RE = Spieler mit beiden Kreuz-Damen + erster Stich-Gewinner (später)
        // Beim Start ist nur der Hochzeitsspieler RE, Partner offen
        foreach ($alleTeilnehmer as $t) {
            $t->setTeam($t === $hochzeitspieler ? Team::RE : Team::KONTRA);
        }

        $spiel->setAktuellerSpielerSitzplatz(1);
        $this->spielStarten($spiel);
    }

    /** @param SpielTeilnehmer[] $alleTeilnehmer */
    private function aufloesenAlsNormalspiel(Spiel $spiel, array $alleTeilnehmer): void
    {
        $spiel->setVariante(SpielVariante::NORMALSPIEL);

        foreach ($alleTeilnehmer as $t) {
            $hatKreuzDame = $t->anzahlKreuzDamen() > 0;
            $t->setTeam($hatKreuzDame ? Team::RE : Team::KONTRA);
        }

        $spiel->setAktuellerSpielerSitzplatz(1);
        $this->spielStarten($spiel);
    }

    private function spielStarten(Spiel $spiel): void
    {
        $spiel->setStatus(SpielStatus::LAUFEND);
        $spiel->setAktuellerStichNr(1);
        $spiel->setAktuellerZugBegannAm(new \DateTimeImmutable());

        $this->em->flush();
        $this->mercurePublisher->spielGestartet($spiel);

        $schwein = $this->trumpfOrdnungFactory->schweinchenHalter($spiel);
        if ($schwein['teilnehmer'] !== null) {
            $this->protokoll->schweinchen($schwein['teilnehmer'], $schwein['super']);
        }

        $vorhand = $spiel->getTeilnehmerBySitzplatz($spiel->getAktuellerSpielerSitzplatz());
        if ($vorhand !== null) {
            $this->protokoll->ereignis($spiel->getTisch(), sprintf('%s kommt raus.', $vorhand->getAnzeigeName()));
        }
    }
}
