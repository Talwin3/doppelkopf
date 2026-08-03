<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Simulation;

use App\Domain\Doppelkopf\Service\SpielGedaechtnis;
use App\Domain\Doppelkopf\Service\TeamSichtbarkeit;
use App\Domain\Doppelkopf\Service\TrumpfOrdnung;
use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\AnsageTyp;
use App\Enum\Kartenfarbe;
use App\Enum\Kartenwert;
use App\Enum\SpielVariante;
use App\Enum\Team;

/**
 * Zustand einer simulierten Partie – die datenbankfreie Entsprechung zu
 * {@see \App\Entity\Spiel} + {@see \App\Repository\GespielteKarteRepository}.
 *
 * Die Lese-Methoden liefern einer Strategie genau das, was ihr Produktiv-Pendant
 * ({@see \App\Application\Doppelkopf\Bot\ProfiStrategie} & Co.) aus der Datenbank
 * zusammenträgt – insbesondere über {@see bekannteTeams()} nur die **öffentlich**
 * sichtbaren Partnerschaften, damit der Benchmark keinen schummelnden Bot misst.
 *
 * `hand()` gibt die Wahrheit heraus; Strategien dürfen ausschließlich ihren eigenen
 * Sitzplatz abfragen.
 */
final class Partie
{
    /** @var array<int, Karte[]> Sitzplatz → Handkarten */
    private array $haende;

    /** @var array<int, int> Sitzplatz → Anzahl Startkarten */
    private array $startGroessen;

    /** @var Karte[] Vereinigung aller Starthände */
    private array $universum;

    /** @var array<int, Karte> Sitzplatz → Karte des laufenden Stichs (Einfügereihenfolge = Spielreihenfolge) */
    private array $offenerStich = [];

    /** @var list<list<array{sitzplatz: int, karte: Karte}>> Abgeschlossene Stiche in Spielreihenfolge */
    private array $abgeschlosseneStiche = [];

    /** @var list<array{sitzplatz: int, stichNr: int, position: int, karte: Karte}> */
    private array $ereignisse = [];

    /** @var list<array{sitzplatz: int, typ: AnsageTyp}> */
    private array $ansagen = [];

    /** @var list<int> Sitze, die eine Kreuz-Dame gespielt haben */
    private array $kreuzDameGespieltVon = [];

    private int $stichNr = 1;

    /**
     * @param array<int, Karte[]> $starthaende      Sitzplatz 1–4 → Handkarten
     * @param array<int, Team>    $teamProSitzplatz Sitzplatz → Team (Spiel-Wahrheit)
     */
    public function __construct(
        array $starthaende,
        private readonly array $teamProSitzplatz,
        private readonly TrumpfOrdnung $ordnung,
        private readonly ?SpielVariante $variante = SpielVariante::NORMALSPIEL,
        private readonly bool $zweiteDulleSticht = false,
    ) {
        $this->haende = [];
        $universum    = [];
        foreach ($starthaende as $sitz => $karten) {
            $this->haende[$sitz]        = array_values($karten);
            $this->startGroessen[$sitz] = count($karten);
            foreach ($karten as $karte) {
                $universum[$karte->id()] = $karte;
            }
        }
        $this->universum = array_values($universum);
    }

    // ── Lesen: allgemeiner Zustand ───────────────────────────────────────────

    public function ordnung(): TrumpfOrdnung
    {
        return $this->ordnung;
    }

    public function variante(): ?SpielVariante
    {
        return $this->variante;
    }

    public function zweiteDulleSticht(): bool
    {
        return $this->zweiteDulleSticht;
    }

    public function stichNr(): int
    {
        return $this->stichNr;
    }

    /** @return array<int, Karte> Sitzplatz → Karte des laufenden Stichs */
    public function offenerStich(): array
    {
        return $this->offenerStich;
    }

    /** Angespielte Karte des laufenden Stichs (null = niemand hat angespielt). */
    public function angespielteKarte(): ?Karte
    {
        return $this->offenerStich === [] ? null : array_values($this->offenerStich)[0];
    }

    /** @return list<array{sitzplatz: int, stichNr: int, position: int, karte: Karte}> */
    public function ereignisse(): array
    {
        return $this->ereignisse;
    }

    /** @return Karte[] */
    public function universum(): array
    {
        return $this->universum;
    }

    /** @return list<array{sitzplatz: int, typ: AnsageTyp}> */
    public function ansagen(): array
    {
        return $this->ansagen;
    }

    /** @return list<int> */
    public function kreuzDameGespieltVon(): array
    {
        return array_values(array_unique($this->kreuzDameGespieltVon));
    }

    /** @return array<int, Team> */
    public function teamProSitzplatz(): array
    {
        return $this->teamProSitzplatz;
    }

    /** @return list<list<array{sitzplatz: int, karte: Karte}>> */
    public function abgeschlosseneStiche(): array
    {
        return $this->abgeschlosseneStiche;
    }

    public function vorbei(): bool
    {
        foreach ($this->haende as $hand) {
            if ($hand !== []) {
                return false;
            }
        }

        return $this->offenerStich === [];
    }

    // ── Lesen: Sicht eines Sitzplatzes ───────────────────────────────────────

    /** @return Karte[] */
    public function hand(int $sitzplatz): array
    {
        return $this->haende[$sitzplatz] ?? [];
    }

    public function kartenInHand(int $sitzplatz): int
    {
        return count($this->haende[$sitzplatz] ?? []);
    }

    public function eigenesTeam(int $sitzplatz): ?Team
    {
        return $this->teamProSitzplatz[$sitzplatz] ?? null;
    }

    /**
     * Handgrößen der jeweils anderen drei Sitze – Eingabe für die Determinisierung.
     *
     * @return array<int, int>
     */
    public function restHandGroessen(int $ohneSitzplatz): array
    {
        $groessen = [];
        foreach ($this->haende as $sitz => $hand) {
            if ($sitz !== $ohneSitzplatz) {
                $groessen[$sitz] = count($hand);
            }
        }

        return $groessen;
    }

    /**
     * Nur die für diesen Sitz **öffentlich bekannten** Teams – identisch zur Regel in
     * {@see \App\Application\Doppelkopf\Bot\OeffentlicheTeams}.
     *
     * @return array<int, ?Team>
     */
    public function bekannteTeams(int $sitzplatz): array
    {
        $angesagtSitze = [];
        foreach ($this->ansagen as $ansage) {
            $angesagtSitze[] = $ansage['sitzplatz'];
        }

        return TeamSichtbarkeit::bekannteTeams(
            $this->teamProSitzplatz,
            $this->variante,
            false,
            [],
            $this->kreuzDameGespieltVon(),
            array_values(array_unique($angesagtSitze)),
            $sitzplatz,
        );
    }

    /** Kartengedächtnis aus Sicht dieses Sitzplatzes. */
    public function gedaechtnisFuer(int $sitzplatz): SpielGedaechtnis
    {
        return SpielGedaechtnis::ausHistorie(
            $this->ereignisse,
            $this->universum,
            $this->hand($sitzplatz),
            $this->ordnung,
        );
    }

    // ── Schreiben ────────────────────────────────────────────────────────────

    public function fuegeAnsageHinzu(int $sitzplatz, AnsageTyp $typ): void
    {
        $this->ansagen[] = ['sitzplatz' => $sitzplatz, 'typ' => $typ];
    }

    public function spieleKarte(int $sitzplatz, Karte $karte): void
    {
        $hand = $this->haende[$sitzplatz] ?? [];
        $index = null;
        foreach ($hand as $i => $k) {
            if ($k->id() === $karte->id()) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new \LogicException(sprintf(
                'Sitzplatz %d hat die Karte %s nicht auf der Hand.',
                $sitzplatz,
                $karte->id(),
            ));
        }

        unset($hand[$index]);
        $this->haende[$sitzplatz] = array_values($hand);

        $this->ereignisse[] = [
            'sitzplatz' => $sitzplatz,
            'stichNr'   => $this->stichNr,
            'position'  => count($this->offenerStich) + 1,
            'karte'     => $karte,
        ];
        $this->offenerStich[$sitzplatz] = $karte;

        if ($karte->farbe === Kartenfarbe::KREUZ && $karte->wert === Kartenwert::DAME) {
            $this->kreuzDameGespieltVon[] = $sitzplatz;
        }
    }

    public function stichVollstaendig(): bool
    {
        return count($this->offenerStich) === 4;
    }

    /** Schließt den laufenden Stich ab und beginnt den nächsten. */
    public function schliesseStich(): void
    {
        $stich = [];
        foreach ($this->offenerStich as $sitz => $karte) {
            $stich[] = ['sitzplatz' => $sitz, 'karte' => $karte];
        }

        $this->abgeschlosseneStiche[] = $stich;
        $this->offenerStich = [];
        $this->stichNr++;
    }
}
