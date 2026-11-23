<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Application\SystemEinstellungService;
use App\Entity\Tisch;
use App\Entity\TischSpieler;
use App\Enum\TischPunkteAnzeige;
use App\Enum\WertungsKategorie;
use App\Repository\SpielTeilnehmerRepository;

/**
 * Punkte, die am Tisch neben den Spielern stehen. Zwei Ansichten, umschaltbar über
 * {@see Tisch::getPunkteAnzeige()}:
 *
 *  - Monatspunkte in der Kategorie des Tisches mit Listenplatz. Gezählt werden nur
 *    global gewertete Spiele, deren Ende in den laufenden Monat (deutsche Zeit) fällt.
 *    Bots haben hier keine Punkte. Einen Listenplatz bekommt nur, wer die Mindestanzahl
 *    Spiele erreicht und sein Profil öffentlich hat; sortiert wird nach Punkten je Spiel.
 *    Bei nicht öffentlichem Profil bleiben auch die Monatspunkte verborgen („privat“).
 *  - Tischliste: alle beendeten Spiele dieses Tisches, Menschen über ihren User (bleibt
 *    beim Wiederkommen erhalten), Bots über ihren Tischplatz (jeder Bot eine eigene Zeile).
 */
final class TischPunkteService
{
    public const ZEITZONE = 'Europe/Berlin';

    private const MONATE = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    public function __construct(
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly SystemEinstellungService $einstellungen,
    ) {}

    /**
     * @return array{
     *     anzeige: TischPunkteAnzeige,
     *     kategorie: WertungsKategorie,
     *     zeitraum: string,
     *     minSpiele: int,
     *     plaetze: list<array{sitzplatz: int, name: string, istBot: bool, punkte: ?int, spiele: int, rang: ?int, ohneRang: ?string}>
     * }
     */
    public function fuerTisch(Tisch $tisch, ?\DateTimeImmutable $jetzt = null): array
    {
        $kategorie = $tisch->getWertungsKategorie();
        $anzeige   = $tisch->getPunkteAnzeige();

        $spieler = $tisch->getAktiveSpieler()->toArray();
        usort($spieler, static fn(TischSpieler $a, TischSpieler $b) => $a->getSitzplatz() <=> $b->getSitzplatz());

        // Zeitraum und Mindestanzahl braucht nur die Monatsansicht.
        $zeitraum  = '';
        $minSpiele = 0;
        if ($anzeige === TischPunkteAnzeige::TISCH) {
            $plaetze = $this->tischliste($tisch, $spieler);
        } else {
            $minSpiele   = $this->einstellungen->getInt('bestenliste_min_spiele_monat');
            [$von, $bis] = self::monatsGrenzen($jetzt ?? new \DateTimeImmutable());
            $zeitraum    = self::MONATE[(int) $von->setTimezone(new \DateTimeZone(self::ZEITZONE))->format('n')];
            $plaetze     = $this->monatspunkte($kategorie, $von, $bis, $minSpiele, $spieler);
        }

        return [
            'anzeige'   => $anzeige,
            'kategorie' => $kategorie,
            'zeitraum'  => $zeitraum,
            'minSpiele' => $minSpiele,
            'plaetze'   => $plaetze,
        ];
    }

    /**
     * Anfang (inklusive) und Ende (exklusive) des Kalendermonats nach deutscher Zeit,
     * umgerechnet in UTC wie in der Datenbank gespeichert.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function monatsGrenzen(\DateTimeImmutable $jetzt): array
    {
        $berlin = new \DateTimeZone(self::ZEITZONE);
        $utc    = new \DateTimeZone('UTC');

        $lokal = $jetzt->setTimezone($berlin);
        $von   = $lokal->setDate((int) $lokal->format('Y'), (int) $lokal->format('n'), 1)->setTime(0, 0);
        $bis   = $von->modify('first day of next month');

        return [$von->setTimezone($utc), $bis->setTimezone($utc)];
    }

    /**
     * Listenplätze nach Punkten je Spiel, gleiche Quote = gleicher Platz.
     * Nur wer gelistet ist (Mindestanzahl erreicht, Profil öffentlich), zählt mit.
     *
     * @param list<array{user_id: string, punkte: int, spiele: int, oeffentlich: bool}> $konten
     * @return array<string, int> user_id => Platz
     */
    public static function listenplaetze(array $konten, int $minSpiele): array
    {
        $gelistet = array_values(array_filter(
            $konten,
            static fn(array $k) => $k['oeffentlich'] && $k['spiele'] > 0 && $k['spiele'] >= $minSpiele,
        ));

        // Absteigend nach Punkten je Spiel; a/b > c/d ohne Gleitkomma: a·d > c·b.
        usort($gelistet, static fn(array $a, array $b) => $b['punkte'] * $a['spiele'] <=> $a['punkte'] * $b['spiele']);

        $plaetze = [];
        $vorher  = null;
        foreach ($gelistet as $i => $k) {
            // Gleiche Quote wie der Vorgänger = gleicher Platz, sonst Position + 1.
            $gleich = $vorher !== null && $k['punkte'] * $vorher['spiele'] === $vorher['punkte'] * $k['spiele'];
            $plaetze[$k['user_id']] = $gleich ? $plaetze[$vorher['user_id']] : $i + 1;
            $vorher = $k;
        }

        return $plaetze;
    }

    /**
     * @param list<TischSpieler> $spieler
     * @return list<array{sitzplatz: int, name: string, istBot: bool, punkte: ?int, spiele: int, rang: ?int, ohneRang: ?string}>
     */
    private function monatspunkte(
        WertungsKategorie $kategorie,
        \DateTimeImmutable $von,
        \DateTimeImmutable $bis,
        int $minSpiele,
        array $spieler,
    ): array {
        $konten = $this->teilnehmerRepo->findeKategorieKonten($kategorie, $von, $bis);
        $plaetze = self::listenplaetze($konten, $minSpiele);

        $jeUser = [];
        foreach ($konten as $k) {
            $jeUser[$k['user_id']] = $k;
        }

        $ergebnis = [];
        foreach ($spieler as $ts) {
            $user = $ts->isIstBot() ? null : $ts->getUser();

            // Nicht öffentliches Profil: Monatspunkte bleiben verborgen, nur „privat“.
            if ($user !== null && !$user->isProfilOeffentlich()) {
                $ergebnis[] = [
                    'sitzplatz' => (int) $ts->getSitzplatz(),
                    'name'      => $ts->getAnzeigeName(),
                    'istBot'    => false,
                    'punkte'    => null,
                    'spiele'    => 0,
                    'rang'      => null,
                    'ohneRang'  => 'privat',
                ];
                continue;
            }

            $userId = $user?->getId()->toRfc4122();
            $konto  = $userId !== null ? ($jeUser[$userId] ?? null) : null;
            $spiele = $konto['spiele'] ?? 0;
            $rang   = $userId !== null ? ($plaetze[$userId] ?? null) : null;

            $ergebnis[] = [
                'sitzplatz' => (int) $ts->getSitzplatz(),
                'name'      => $ts->getAnzeigeName(),
                'istBot'    => $user === null,
                'punkte'    => $user === null ? null : ($konto['punkte'] ?? 0),
                'spiele'    => $spiele,
                'rang'      => $rang,
                // Öffentlich, aber ohne Platz: Es fehlen noch Spiele (bzw. überhaupt eins).
                'ohneRang'  => $user !== null && $rang === null ? 'spiele' : null,
            ];
        }

        return $ergebnis;
    }

    /**
     * @param list<TischSpieler> $spieler
     * @return list<array{sitzplatz: int, name: string, istBot: bool, punkte: ?int, spiele: int, rang: ?int, ohneRang: ?string}>
     */
    private function tischliste(Tisch $tisch, array $spieler): array
    {
        $jeUser = [];
        $jeBot  = [];
        foreach ($this->teilnehmerRepo->findeTischKonten($tisch) as $k) {
            if ($k['bot_kennung'] !== null) {
                $jeBot[$k['bot_kennung']] = $k;
            } elseif ($k['user_id'] !== null) {
                $jeUser[$k['user_id']] = $k;
            }
        }

        $ergebnis = [];
        foreach ($spieler as $ts) {
            $konto = $ts->isIstBot()
                ? ($jeBot[$ts->getId()->toRfc4122()] ?? null)
                : ($ts->getUser() !== null ? ($jeUser[$ts->getUser()->getId()->toRfc4122()] ?? null) : null);

            $ergebnis[] = [
                'sitzplatz' => (int) $ts->getSitzplatz(),
                'name'      => $ts->getAnzeigeName(),
                'istBot'    => $ts->isIstBot(),
                'punkte'    => $konto['punkte'] ?? 0,
                'spiele'    => $konto['spiele'] ?? 0,
                'rang'      => null,
                'ohneRang'  => null,
            ];
        }

        return $ergebnis;
    }
}
