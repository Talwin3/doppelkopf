<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Doppelkopf\BestenlisteService;
use App\Application\Doppelkopf\BestenlistenZeitraum;
use App\Entity\User;
use App\Enum\AnsageTyp;
use App\Enum\SpielVariante;
use App\Enum\WertungsKategorie;
use App\Repository\SpielTeilnehmerRepository;

/**
 * Statistik auf der Profilseite. Gezählt werden alle beendeten Spiele mit Abrechnung,
 * wahlweise nur eine Kategorie, nur global gewertete Spiele und ein Zeitraum (Monat
 * oder Jahr nach deutscher Zeit; null = gesamt).
 */
final class ProfilStatistikService
{
    /** Länge des Punkteverlaufs in Monaten. */
    public const VERLAUF_MONATE = 12;

    private const MONATE_KURZ = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    public function __construct(
        private readonly SpielTeilnehmerRepository $teilnehmerRepo,
        private readonly BestenlisteService $bestenliste,
    ) {}

    /**
     * @return array{
     *     grund: array{spiele: int, siege: int, punkte: int, siegquote: float, schnitt: float},
     *     parteien: array{RE: array{spiele: int, siege: int, punkte: int, anteil: float, siegquote: float, schnitt: float}, KONTRA: array{spiele: int, siege: int, punkte: int, anteil: float, siegquote: float, schnitt: float}},
     *     soli: array{spiele: int, siege: int, punkte: int, anteil: float, arten: list<array{label: string, spiele: int, siege: int, punkte: int}>},
     *     sonder: list<array{label: string, spiele: int, siege: int}>,
     *     ansagen: list<array{label: string, anzahl: int, gehalten: int, quote: float}>,
     *     verlauf: array{titel: string, monate: list<array{schluessel: string, label: string, punkte: int, spiele: int, markiert: bool}>}
     * }
     */
    public function fuerUser(
        User $user,
        ?WertungsKategorie $kategorie,
        ?BestenlistenZeitraum $zeitraum,
        bool $nurGewertet,
        \DateTimeImmutable $jetzt,
    ): array {
        [$von, $bis] = $zeitraum?->grenzen() ?? [null, null];
        $roh = $this->teilnehmerRepo->findeProfilStatistik($user, $kategorie, $nurGewertet, $von, $bis);

        // Grundzahlen über alle Zeilen, auch eine ohne Partei (sollte nach der Abrechnung
        // nicht vorkommen, darf aber nicht stillschweigend aus der Summe fallen).
        $parteien = ['RE' => self::leer(), 'KONTRA' => self::leer()];
        $grund    = self::leer();
        foreach ($roh['parteien'] as $z) {
            $werte = ['spiele' => $z['spiele'], 'siege' => $z['siege'], 'punkte' => $z['punkte']];
            if (isset($parteien[$z['team']])) {
                $parteien[$z['team']] = $werte;
            }
            $grund['spiele'] += $werte['spiele'];
            $grund['siege']  += $werte['siege'];
            $grund['punkte'] += $werte['punkte'];
        }

        return [
            'grund'    => self::mitQuoten($grund),
            'parteien' => array_map(static fn(array $p) => self::mitQuoten($p) + ['anteil' => self::prozent($p['spiele'], $grund['spiele'])], $parteien),
            'soli'     => $this->soli($roh['soli'], $grund['spiele']),
            'sonder'   => self::sonder($roh['sonder']),
            'ansagen'  => self::ansagen($roh['ansagen']),
            'verlauf'  => $this->verlauf($user, $kategorie, $zeitraum, $nurGewertet, $jetzt),
        ];
    }

    /**
     * Bestenlisten-Plätze passend zum gewählten Zeitraum: beim Monat dieser Monat und sein
     * Jahr, beim Jahr nur das Jahr, bei „gesamt“ (null) der laufende Monat und das laufende
     * Jahr. Leer, wenn der Spieler nicht in Bestenlisten erscheinen will. Bei mehreren
     * Kategorien fehlen die, in denen er im Zeitraum kein gewertetes Spiel hat.
     *
     * @param list<WertungsKategorie> $kategorien
     * @return list<array{kategorie: WertungsKategorie, plaetze: list<array{zeitraum: BestenlistenZeitraum, label: string, platz: ?int, spiele: int, minSpiele: int}>}>
     */
    public function listenplaetze(User $user, array $kategorien, ?BestenlistenZeitraum $zeitraum, \DateTimeImmutable $jetzt): array
    {
        if (!$user->isGelistet()) {
            return [];
        }

        $zeitraeume = match ($zeitraum?->art) {
            BestenlistenZeitraum::MONAT => [$zeitraum, BestenlistenZeitraum::jahr($zeitraum->jahr)],
            BestenlistenZeitraum::JAHR  => [$zeitraum],
            default                     => [
                BestenlistenZeitraum::aktuell(BestenlistenZeitraum::MONAT, $jetzt),
                BestenlistenZeitraum::aktuell(BestenlistenZeitraum::JAHR, $jetzt),
            ],
        };

        $id = $user->getId()->toRfc4122();
        $ergebnis = [];
        foreach ($kategorien as $kategorie) {
            $plaetze = [];
            foreach ($zeitraeume as $z) {
                $plaetze[] = ['zeitraum' => $z, 'label' => $z->label()] + $this->platz($id, $kategorie, $z);
            }
            // Bei mehreren Kategorien nur die, in denen im Zeitraum überhaupt gespielt wurde.
            if (count($kategorien) > 1 && array_sum(array_column($plaetze, 'spiele')) === 0) {
                continue;
            }
            $ergebnis[] = ['kategorie' => $kategorie, 'plaetze' => $plaetze];
        }

        return $ergebnis;
    }

    /** @return array{platz: ?int, spiele: int, minSpiele: int} */
    private function platz(string $userId, WertungsKategorie $kategorie, BestenlistenZeitraum $zeitraum): array
    {
        $konten    = $this->bestenliste->konten($kategorie, $zeitraum);
        $minSpiele = $this->bestenliste->mindestSpiele($zeitraum);

        $spiele = 0;
        foreach ($konten as $k) {
            if ($k['user_id'] === $userId) {
                $spiele = $k['spiele'];
                break;
            }
        }

        return [
            'platz'     => BestenlisteService::listenplaetze($konten, $minSpiele)[$userId] ?? null,
            'spiele'    => $spiele,
            'minSpiele' => $minSpiele,
        ];
    }

    /**
     * Zwölf Monate Punkteverlauf: das gewählte Jahr (Januar–Dezember), bis zum gewählten
     * Monat bzw. bei „gesamt“ bis zum laufenden Monat. Der gewählte Monat ist markiert.
     *
     * @return array{titel: string, monate: list<array{schluessel: string, label: string, punkte: int, spiele: int, markiert: bool}>}
     */
    private function verlauf(
        User $user,
        ?WertungsKategorie $kategorie,
        ?BestenlistenZeitraum $zeitraum,
        bool $nurGewertet,
        \DateTimeImmutable $jetzt,
    ): array {
        $letzter = match ($zeitraum?->art) {
            BestenlistenZeitraum::JAHR  => BestenlistenZeitraum::monat($zeitraum->jahr, 12),
            BestenlistenZeitraum::MONAT => $zeitraum,
            default                     => BestenlistenZeitraum::aktuell(BestenlistenZeitraum::MONAT, $jetzt),
        };
        $erster = $letzter;
        for ($i = 1; $i < self::VERLAUF_MONATE; $i++) {
            $erster = $erster->vorheriger();
        }

        [$von]    = $erster->grenzen();
        [, $bis]  = $letzter->grenzen();
        $werte    = $this->teilnehmerRepo->findeProfilVerlauf($user, $kategorie, $nurGewertet, $von, $bis);

        $monate = [];
        for ($m = $erster, $i = 0; $i < self::VERLAUF_MONATE; $m = $m->naechster(), $i++) {
            $schluessel = sprintf('%04d-%02d', $m->jahr, $m->monat);
            $monate[] = [
                'schluessel' => $schluessel,
                'label'      => self::MONATE_KURZ[$m->monat],
                'punkte'     => $werte[$schluessel]['punkte'] ?? 0,
                'spiele'     => $werte[$schluessel]['spiele'] ?? 0,
                'markiert'   => $zeitraum?->art === BestenlistenZeitraum::MONAT && $m->jahr === $zeitraum->jahr && $m->monat === $zeitraum->monat,
            ];
        }

        $titel = $zeitraum?->art === BestenlistenZeitraum::JAHR
            ? 'Punkteverlauf ' . $zeitraum->jahr
            : 'Punkteverlauf ' . $erster->label() . ' – ' . $letzter->label();

        return ['titel' => $titel, 'monate' => $monate];
    }

    /**
     * @param list<array{variante: string, spiele: int, siege: int, punkte: int}> $roh
     * @return array{spiele: int, siege: int, punkte: int, anteil: float, arten: list<array{label: string, spiele: int, siege: int, punkte: int}>}
     */
    private function soli(array $roh, int $alleSpiele): array
    {
        $jeArt = [];
        foreach ($roh as $z) {
            $jeArt[$z['variante']] = $z;
        }

        $summe = self::leer();
        $arten = [];
        // Feste Reihenfolge wie im Vorbehalts-Dialog, nur gespielte Arten.
        foreach (SpielVariante::cases() as $variante) {
            if (!isset($jeArt[$variante->value])) {
                continue;
            }
            $z = $jeArt[$variante->value];
            $arten[] = ['label' => $variante->label(), 'spiele' => $z['spiele'], 'siege' => $z['siege'], 'punkte' => $z['punkte']];
            $summe['spiele'] += $z['spiele'];
            $summe['siege']  += $z['siege'];
            $summe['punkte'] += $z['punkte'];
        }

        return $summe + ['anteil' => self::prozent($summe['spiele'], $alleSpiele), 'arten' => $arten];
    }

    /**
     * @param array<string, int> $roh
     * @return list<array{label: string, spiele: int, siege: int}>
     */
    private static function sonder(array $roh): array
    {
        $zeilen = [
            'hochzeit'          => 'Hochzeit',
            'stille_hochzeit'   => 'Stille Hochzeit',
            'armut'             => 'Armut (eigene)',
            'armut_uebernommen' => 'Armut übernommen',
        ];

        $ergebnis = [];
        foreach ($zeilen as $schluessel => $label) {
            $ergebnis[] = ['label' => $label, 'spiele' => $roh[$schluessel] ?? 0, 'siege' => $roh[$schluessel . '_siege'] ?? 0];
        }

        return $ergebnis;
    }

    /**
     * @param list<array{ansage_typ: string, anzahl: int, gehalten: int}> $roh
     * @return list<array{label: string, anzahl: int, gehalten: int, quote: float}>
     */
    private static function ansagen(array $roh): array
    {
        $jeTyp = [];
        foreach ($roh as $z) {
            $jeTyp[$z['ansage_typ']] = $z;
        }

        $ergebnis = [];
        foreach (SpielTeilnehmerRepository::STATISTIK_ANSAGEN as $typ) {
            $z = $jeTyp[$typ] ?? ['anzahl' => 0, 'gehalten' => 0];
            $ergebnis[] = [
                'label'    => AnsageTyp::from($typ)->label(),
                'anzahl'   => $z['anzahl'],
                'gehalten' => $z['gehalten'],
                'quote'    => self::prozent($z['gehalten'], $z['anzahl']),
            ];
        }

        return $ergebnis;
    }

    /** @return array{spiele: int, siege: int, punkte: int} */
    private static function leer(): array
    {
        return ['spiele' => 0, 'siege' => 0, 'punkte' => 0];
    }

    /**
     * @param array{spiele: int, siege: int, punkte: int} $werte
     * @return array{spiele: int, siege: int, punkte: int, siegquote: float, schnitt: float}
     */
    private static function mitQuoten(array $werte): array
    {
        return $werte + [
            'siegquote' => self::prozent($werte['siege'], $werte['spiele']),
            'schnitt'   => $werte['spiele'] > 0 ? round($werte['punkte'] / $werte['spiele'], 2) : 0.0,
        ];
    }

    private static function prozent(int $teil, int $ganzes): float
    {
        return $ganzes > 0 ? round($teil / $ganzes * 100, 1) : 0.0;
    }
}
