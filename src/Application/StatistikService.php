<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\User;
use App\Repository\SpielTeilnehmerRepository;

final class StatistikService
{
    public function __construct(
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
    ) {}

    /**
     * Vollständige Statistik für eine Profilseite.
     *
     * @return array{
     *   spiele: int,
     *   siege: int,
     *   niederlagen: int,
     *   siegquote: float,
     *   punkte: int,
     *   punkteSchnitt: float,
     *   punkteProMonat: array<int, array{label: string, punkte: int, spiele: int}>
     * }
     */
    public function fuerUser(User $user): array
    {
        $basis = $this->teilnehmerRepo->findeUserStatistik($user);

        $spiele      = (int) $basis['spiele'];
        $siege       = (int) $basis['siege'];
        $punkte      = (int) $basis['punkte'];
        $niederlagen = $spiele - $siege;
        $siegquote   = $spiele > 0 ? round($siege / $spiele * 100, 1) : 0.0;
        $schnitt     = $spiele > 0 ? round($punkte / $spiele, 1) : 0.0;

        $roheMonatsDaten = $this->teilnehmerRepo->findePunkteProMonat($user, 6);
        $punkteProMonat  = $this->aufFuellenMonatsliste($roheMonatsDaten, 6);

        return [
            'spiele'         => $spiele,
            'siege'          => $siege,
            'niederlagen'    => $niederlagen,
            'siegquote'      => $siegquote,
            'punkte'         => $punkte,
            'punkteSchnitt'  => $schnitt,
            'punkteProMonat' => $punkteProMonat,
        ];
    }

    /**
     * @return array<int, array{id: string, username: string, punkte: int, spiele: int, siege: int, rang: int}>
     */
    public function bestelisteGesamt(int $limit = 20): array
    {
        return $this->mitRang($this->teilnehmerRepo->findeBestelisteGesamt($limit));
    }

    /**
     * @return array<int, array{id: string, username: string, punkte: int, spiele: int, siege: int, rang: int}>
     */
    public function bestelisteMonat(int $limit = 20): array
    {
        return $this->mitRang($this->teilnehmerRepo->findeBestelisteMonat($limit));
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Füllt die letzten $monate Kalendermonatem auf (auch wenn kein Spiel stattfand).
     *
     * @param array<int, array{jahr: int|string, monat: int|string, punkte: int|string, spiele: int|string}> $roh
     * @return array<int, array{label: string, punkte: int, spiele: int}>
     */
    private function aufFuellenMonatsliste(array $roh, int $monate): array
    {
        // Index nach 'YYYY-MM'
        $indiziert = [];
        foreach ($roh as $r) {
            $key              = sprintf('%04d-%02d', $r['jahr'], $r['monat']);
            $indiziert[$key]  = ['punkte' => (int) $r['punkte'], 'spiele' => (int) $r['spiele']];
        }

        $liste  = [];
        $cursor = (new \DateTimeImmutable('first day of this month'))->modify('-' . ($monate - 1) . ' months');

        for ($i = 0; $i < $monate; $i++) {
            $key     = $cursor->format('Y-m');
            $label   = $cursor->format('M Y');        // z. B. "Jan 2026"
            $eintrag = $indiziert[$key] ?? ['punkte' => 0, 'spiele' => 0];
            $liste[] = array_merge(['label' => $label], $eintrag);
            $cursor  = $cursor->modify('+1 month');
        }

        return $liste;
    }

    private function mitRang(array $zeilen): array
    {
        $rang = 1;
        foreach ($zeilen as &$z) {
            $z['rang']  = $rang++;
            $z['punkte'] = (int) $z['punkte'];
            $z['spiele'] = (int) $z['spiele'];
            $z['siege']  = (int) $z['siege'];
        }
        return $zeilen;
    }
}
