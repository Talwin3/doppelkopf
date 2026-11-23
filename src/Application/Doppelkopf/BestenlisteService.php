<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Application\SystemEinstellungService;
use App\Enum\WertungsKategorie;
use App\Repository\SpielTeilnehmerRepository;

/**
 * Monats- und Jahresbestenlisten je Wertungskategorie.
 *
 * Gezählt werden nur global gewertete Spiele mit Abrechnung, deren Ende in den Zeitraum
 * fällt (deutsche Zeit). Gelistet ist, wer die Mindestanzahl Spiele erreicht und
 * gelistet werden will ({@see \App\Entity\User::isGelistet()}). Dieselben Regeln gelten
 * für den Listenplatz in der Punkteleiste am Tisch ({@see TischPunkteService}).
 */
final class BestenlisteService
{
    public const NACH_SCHNITT = 'schnitt';
    public const NACH_PUNKTEN = 'punkte';

    public function __construct(
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly SystemEinstellungService $einstellungen,
    ) {}

    /** Mindestanzahl Spiele für einen Listenplatz im Zeitraum (Admin-Einstellung). */
    public function mindestSpiele(BestenlistenZeitraum $zeitraum): int
    {
        return $this->einstellungen->getInt(
            $zeitraum->art === BestenlistenZeitraum::JAHR ? 'bestenliste_min_spiele_jahr' : 'bestenliste_min_spiele_monat',
        );
    }

    /**
     * Konten aller Menschen in Kategorie und Zeitraum, auch der nicht gelisteten
     * (der Aufrufer braucht sie z. B. für die eigenen Punkte am Tisch).
     *
     * @return list<array{user_id: string, username: string, avatar_stil: string, avatar_seed: string, punkte: int, spiele: int, gelistet: bool}>
     */
    public function konten(WertungsKategorie $kategorie, BestenlistenZeitraum $zeitraum): array
    {
        [$von, $bis] = $zeitraum->grenzen();

        return $this->teilnehmerRepo->findeKategorieKonten($kategorie, $von, $bis);
    }

    /**
     * Alles für die Bestenlisten-Seite: Mindestanzahl, alle Konten (auch nicht gelistete,
     * z. B. für den eigenen Stand) und die Liste der Gelisteten mit Platz.
     *
     * @return array{
     *     minSpiele: int,
     *     konten: list<array{user_id: string, username: string, avatar_stil: string, avatar_seed: string, punkte: int, spiele: int, gelistet: bool}>,
     *     liste: list<array{user_id: string, username: string, avatar_stil: string, avatar_seed: string, punkte: int, spiele: int, gelistet: bool, rang: int}>
     * }
     */
    public function ansicht(WertungsKategorie $kategorie, BestenlistenZeitraum $zeitraum, string $sortierung = self::NACH_SCHNITT): array
    {
        $minSpiele = $this->mindestSpiele($zeitraum);
        $konten    = $this->konten($kategorie, $zeitraum);

        return [
            'minSpiele' => $minSpiele,
            'konten'    => $konten,
            'liste'     => self::rangliste($konten, $minSpiele, $sortierung),
        ];
    }

    /**
     * Wie viele Spieler im Zeitraum gespielt haben, aber (noch) nicht gelistet sind,
     * weil ihnen Spiele fehlen. Wer nicht gelistet werden will, zählt nicht mit.
     *
     * @param list<array{gelistet: bool, spiele: int}> $konten
     */
    public static function anzahlUnterMindestanzahl(array $konten, int $minSpiele): int
    {
        return count(array_filter(
            $konten,
            static fn(array $k) => $k['gelistet'] && $k['spiele'] > 0 && $k['spiele'] < $minSpiele,
        ));
    }

    /**
     * Sortiert die gelisteten Konten und vergibt Plätze; gleicher Wert = gleicher Platz.
     * Nach Punkten je Spiel (Standard) oder nach Punkten; bei Gleichstand stehen in der
     * Anzeige die mit mehr Spielen bzw. dem besseren Schnitt vorn, der Platz bleibt gleich.
     *
     * @template T of array{user_id: string, punkte: int, spiele: int, gelistet: bool}
     * @param list<T> $konten
     * @return list<T&array{rang: int}>
     */
    public static function rangliste(array $konten, int $minSpiele, string $sortierung = self::NACH_SCHNITT): array
    {
        $gelistet = array_values(array_filter(
            $konten,
            static fn(array $k) => $k['gelistet'] && $k['spiele'] > 0 && $k['spiele'] >= $minSpiele,
        ));

        // a/b <=> c/d ohne Gleitkomma: a·d <=> c·b (Spiele sind hier immer > 0).
        $schnitt = static fn(array $a, array $b): int => $b['punkte'] * $a['spiele'] <=> $a['punkte'] * $b['spiele'];
        $punkte  = static fn(array $a, array $b): int => $b['punkte'] <=> $a['punkte'];
        [$haupt, $neben] = $sortierung === self::NACH_PUNKTEN ? [$punkte, $schnitt] : [$schnitt, $punkte];

        usort($gelistet, static fn(array $a, array $b): int
            => $haupt($a, $b) ?: $neben($a, $b) ?: $b['spiele'] <=> $a['spiele'] ?: strcmp($a['user_id'], $b['user_id']));

        $vorher = null;
        foreach ($gelistet as $i => &$k) {
            $k['rang'] = $vorher !== null && $haupt($vorher, $k) === 0 ? $vorher['rang'] : $i + 1;
            $vorher = $k;
        }
        unset($k);

        return $gelistet;
    }

    /**
     * Listenplätze nach Punkten je Spiel, als Zuordnung user_id => Platz.
     *
     * @param list<array{user_id: string, punkte: int, spiele: int, gelistet: bool}> $konten
     * @return array<string, int>
     */
    public static function listenplaetze(array $konten, int $minSpiele): array
    {
        $plaetze = [];
        foreach (self::rangliste($konten, $minSpiele) as $k) {
            $plaetze[$k['user_id']] = $k['rang'];
        }

        return $plaetze;
    }
}
