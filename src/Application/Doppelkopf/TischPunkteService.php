<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

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
 *    Bots haben hier keine Punkte. Der Listenplatz ist der aus der Monatsbestenliste
 *    ({@see BestenlisteService}): Mindestanzahl Spiele, sortiert nach Punkten je Spiel.
 *    Wer nicht gelistet werden will ({@see \App\Entity\User::isGelistet()}), bekommt
 *    auch keine Monatspunkte angezeigt („privat“).
 *  - Tischliste: alle beendeten Spiele dieses Tisches, Menschen über ihren User (bleibt
 *    beim Wiederkommen erhalten), Bots über ihren Tischplatz (jeder Bot eine eigene Zeile).
 */
final class TischPunkteService
{
    public function __construct(
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly BestenlisteService $bestenliste,
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
            $monat     = BestenlistenZeitraum::aktuell(BestenlistenZeitraum::MONAT, $jetzt ?? new \DateTimeImmutable());
            $minSpiele = $this->bestenliste->mindestSpiele($monat);
            $zeitraum  = $monat->monatsname();
            $plaetze   = $this->monatspunkte($kategorie, $monat, $minSpiele, $spieler);
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
     * @param list<TischSpieler> $spieler
     * @return list<array{sitzplatz: int, name: string, istBot: bool, punkte: ?int, spiele: int, rang: ?int, ohneRang: ?string}>
     */
    private function monatspunkte(
        WertungsKategorie $kategorie,
        BestenlistenZeitraum $monat,
        int $minSpiele,
        array $spieler,
    ): array {
        $konten  = $this->bestenliste->konten($kategorie, $monat);
        $plaetze = BestenlisteService::listenplaetze($konten, $minSpiele);

        $jeUser = [];
        foreach ($konten as $k) {
            $jeUser[$k['user_id']] = $k;
        }

        $ergebnis = [];
        foreach ($spieler as $ts) {
            $user = $ts->isIstBot() ? null : $ts->getUser();

            // Nicht gelistet (Profil privat oder nicht in Bestenlisten): nur „privat“.
            if ($user !== null && !$user->isGelistet()) {
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
