<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Entity\Tisch;
use App\Entity\User;
use App\Enum\AvatarStil;
use App\Enum\SpielStatus;
use App\Enum\WertungsKategorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpielTeilnehmer>
 */
class SpielTeilnehmerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpielTeilnehmer::class);
    }

    public function findBySpielAndUser(Spiel $spiel, User $user): ?SpielTeilnehmer
    {
        return $this->createQueryBuilder('st')
            ->where('st.spiel = :spiel')
            ->andWhere('st.user = :user')
            ->setParameter('spiel', $spiel)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Ansagen, die in die Profilstatistik eingehen (ohne die Vorbehalts-Einträge). */
    public const STATISTIK_ANSAGEN = ['RE', 'CONTRA', 'KEINE_NEUN', 'KEINE_SECHS', 'KEINE_DREI', 'SCHWARZ'];

    /**
     * Profilstatistik eines Menschen: alle beendeten Spiele mit Abrechnung, wahlweise
     * eingeschränkt auf eine Kategorie, nur global gewertete Spiele und einen Zeitraum
     * [$von, $bis) (UTC wie in der DB).
     *
     * @return array{
     *     parteien: list<array{team: ?string, spiele: int, siege: int, punkte: int}>,
     *     soli: list<array{variante: string, spiele: int, siege: int, punkte: int}>,
     *     sonder: array<string, int>,
     *     ansagen: list<array{ansage_typ: string, anzahl: int, gehalten: int}>
     * }
     */
    public function findeProfilStatistik(
        User $user,
        ?WertungsKategorie $kategorie,
        bool $nurGewertet,
        ?\DateTimeImmutable $von = null,
        ?\DateTimeImmutable $bis = null,
    ): array {
        [$wo, $parameter] = self::profilFilter($user, $kategorie, $nurGewertet, $von, $bis);
        $conn = $this->getEntityManager()->getConnection();
        $basis = 'FROM spiel_teilnehmer st JOIN spiele s ON s.id = st.spiel_id WHERE ' . $wo;

        $parteien = $conn->executeQuery(
            'SELECT st.team,
                    COUNT(*)                                AS spiele,
                    COUNT(*) FILTER (WHERE st.gewonnen)     AS siege,
                    COALESCE(SUM(st.punkte_delta), 0)       AS punkte
             ' . $basis . '
             GROUP BY st.team',
            $parameter,
        )->fetchAllAssociative();

        // Eigene Soli: Der Solist ist der einzige Re-Spieler.
        $soli = $conn->executeQuery(
            "SELECT s.variante,
                    COUNT(*)                                AS spiele,
                    COUNT(*) FILTER (WHERE st.gewonnen)     AS siege,
                    COALESCE(SUM(st.punkte_delta), 0)       AS punkte
             " . $basis . "
               AND st.team = 'RE'
               AND s.variante LIKE 'SOLO\\_%'
             GROUP BY s.variante",
            $parameter,
        )->fetchAllAssociative();

        // Hochzeit (angesagt bzw. still, TSR 4.4.5) und Armut (eigene bzw. übernommene).
        $sonder = $conn->executeQuery(
            "SELECT COUNT(*) FILTER (WHERE s.variante = 'HOCHZEIT' AND st.vorbehalt_typ = 'HOCHZEIT')                        AS hochzeit,
                    COUNT(*) FILTER (WHERE s.variante = 'HOCHZEIT' AND st.vorbehalt_typ = 'HOCHZEIT' AND st.gewonnen)       AS hochzeit_siege,
                    COUNT(*) FILTER (WHERE s.variante = 'NORMALSPIEL' AND s.hochzeit_als_solo AND st.team = 'RE')               AS stille_hochzeit,
                    COUNT(*) FILTER (WHERE s.variante = 'NORMALSPIEL' AND s.hochzeit_als_solo AND st.team = 'RE' AND st.gewonnen) AS stille_hochzeit_siege,
                    COUNT(*) FILTER (WHERE s.armut_spieler_sitzplatz = st.sitzplatz)                                         AS armut,
                    COUNT(*) FILTER (WHERE s.armut_spieler_sitzplatz = st.sitzplatz AND st.gewonnen)                        AS armut_siege,
                    COUNT(*) FILTER (WHERE s.armut_annehmer_sitzplatz = st.sitzplatz)                                        AS armut_uebernommen,
                    COUNT(*) FILTER (WHERE s.armut_annehmer_sitzplatz = st.sitzplatz AND st.gewonnen)                       AS armut_uebernommen_siege
             " . $basis,
            $parameter,
        )->fetchAssociative() ?: [];

        // Ansagen des Spielers; gehalten heißt: seine Partei hat gewonnen (TSR 7.1 –
        // eine Ansage ist eine Verpflichtung, wer sie verfehlt, verliert).
        $ansagen = $conn->executeQuery(
            'SELECT a.ansage_typ,
                    COUNT(*)                                AS anzahl,
                    COUNT(*) FILTER (WHERE st.gewonnen)     AS gehalten
             FROM spiel_teilnehmer st
             JOIN spiele s         ON s.id = st.spiel_id
             JOIN spiel_ansagen a  ON a.spiel_id = s.id AND a.sitzplatz = st.sitzplatz
             WHERE ' . $wo . '
               AND a.ansage_typ IN (' . implode(', ', array_fill(0, count(self::STATISTIK_ANSAGEN), '?')) . ')
             GROUP BY a.ansage_typ',
            [...$parameter, ...self::STATISTIK_ANSAGEN],
        )->fetchAllAssociative();

        return [
            'parteien' => array_map(static fn(array $z) => [
                'team'   => $z['team'] !== null ? (string) $z['team'] : null,
                'spiele' => (int) $z['spiele'],
                'siege'  => (int) $z['siege'],
                'punkte' => (int) $z['punkte'],
            ], $parteien),
            'soli' => array_map(static fn(array $z) => [
                'variante' => (string) $z['variante'],
                'spiele'   => (int) $z['spiele'],
                'siege'    => (int) $z['siege'],
                'punkte'   => (int) $z['punkte'],
            ], $soli),
            'sonder'  => array_map('intval', $sonder),
            'ansagen' => array_map(static fn(array $z) => [
                'ansage_typ' => (string) $z['ansage_typ'],
                'anzahl'     => (int) $z['anzahl'],
                'gehalten'   => (int) $z['gehalten'],
            ], $ansagen),
        ];
    }

    /**
     * Punkte und Spiele je Kalendermonat nach deutscher Zeit im Zeitraum [$von, $bis).
     *
     * @return array<string, array{punkte: int, spiele: int}> 'YYYY-MM' => Werte
     */
    public function findeProfilVerlauf(
        User $user,
        ?WertungsKategorie $kategorie,
        bool $nurGewertet,
        \DateTimeImmutable $von,
        \DateTimeImmutable $bis,
    ): array {
        [$wo, $parameter] = self::profilFilter($user, $kategorie, $nurGewertet, $von, $bis);

        $zeilen = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT to_char((s.beendet_am AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Berlin', 'YYYY-MM') AS monat,
                    COALESCE(SUM(st.punkte_delta), 0) AS punkte,
                    COUNT(*)                          AS spiele
             FROM spiel_teilnehmer st
             JOIN spiele s ON s.id = st.spiel_id
             WHERE " . $wo . '
             GROUP BY 1',
            $parameter,
        )->fetchAllAssociative();

        $ergebnis = [];
        foreach ($zeilen as $z) {
            $ergebnis[(string) $z['monat']] = ['punkte' => (int) $z['punkte'], 'spiele' => (int) $z['spiele']];
        }

        return $ergebnis;
    }

    /**
     * Spielende des frühesten abgerechneten Spiels eines Menschen in der gewählten
     * Auswahl (begrenzt das Blättern).
     */
    public function findeFruehestesSpielende(User $user, ?WertungsKategorie $kategorie = null, bool $nurGewertet = false): ?\DateTimeImmutable
    {
        [$wo, $parameter] = self::profilFilter($user, $kategorie, $nurGewertet);

        $wert = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT MIN(s.beendet_am) FROM spiel_teilnehmer st JOIN spiele s ON s.id = st.spiel_id WHERE ' . $wo,
            $parameter,
        )->fetchOne();

        return $wert ? new \DateTimeImmutable((string) $wert, new \DateTimeZone('UTC')) : null;
    }

    /**
     * Gemeinsame WHERE-Bedingung der Profilabfragen.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function profilFilter(
        User $user,
        ?WertungsKategorie $kategorie,
        bool $nurGewertet,
        ?\DateTimeImmutable $von = null,
        ?\DateTimeImmutable $bis = null,
    ): array {
        $wo = 'st.user_id = ? AND st.ist_bot = false AND s.status = ? AND s.wertung_details IS NOT NULL';
        $parameter = [$user->getId()->toRfc4122(), SpielStatus::BEENDET->value];

        if ($kategorie !== null) {
            $wo .= ' AND s.kategorie = ?';
            $parameter[] = $kategorie->value;
        }
        if ($nurGewertet) {
            $wo .= ' AND s.global_gewertet = true';
        }
        if ($von !== null) {
            $wo .= ' AND s.beendet_am >= ?';
            $parameter[] = $von->format('Y-m-d H:i:s');
        }
        if ($bis !== null) {
            $wo .= ' AND s.beendet_am < ?';
            $parameter[] = $bis->format('Y-m-d H:i:s');
        }

        return [$wo, $parameter];
    }

    /**
     * Punktekonten aller Menschen in einer Kategorie für einen Zeitraum: nur beendete,
     * global gewertete Spiele, Spielende in [$von, $bis). Zeitpunkte in UTC wie in der DB.
     * Nur Spiele mit Abrechnung: Ein nach abgelehnter Armut neu gegebenes Spiel ist auch
     * BEENDET, wurde aber nie gespielt.
     *
     * `gelistet`: Profil öffentlich UND Bestenlisten-Schalter an ({@see User::isGelistet()}).
     *
     * @return list<array{user_id: string, username: string, avatar_stil: string, avatar_seed: string, punkte: int, spiele: int, gelistet: bool}>
     */
    public function findeKategorieKonten(WertungsKategorie $kategorie, \DateTimeImmutable $von, \DateTimeImmutable $bis): array
    {
        $zeilen = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT st.user_id,
                    u.username,
                    u.avatar_stil,
                    u.avatar_seed,
                    COALESCE(SUM(st.punkte_delta), 0)                AS punkte,
                    COUNT(st.id)                                     AS spiele,
                    (u.profil_oeffentlich AND u.in_bestenlisten)     AS gelistet
             FROM spiel_teilnehmer st
             JOIN spiele s ON s.id = st.spiel_id
             JOIN users  u ON u.id = st.user_id
             WHERE st.ist_bot       = false
               AND s.status         = ?
               AND s.wertung_details IS NOT NULL
               AND s.global_gewertet = true
               AND s.kategorie      = ?
               AND s.beendet_am    >= ?
               AND s.beendet_am     < ?
             GROUP BY st.user_id, u.username, u.avatar_stil, u.avatar_seed, u.profil_oeffentlich, u.in_bestenlisten',
            [
                SpielStatus::BEENDET->value,
                $kategorie->value,
                $von->format('Y-m-d H:i:s'),
                $bis->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();

        return array_map(static fn(array $z): array => [
            'user_id'     => (string) $z['user_id'],
            'username'    => (string) $z['username'],
            // Dieselben Ersatzwerte wie User::getAvatarStil()/getAvatarSeed().
            'avatar_stil' => AvatarStil::vonWert((string) $z['avatar_stil'])->value,
            'avatar_seed' => (string) $z['avatar_seed'] !== '' ? (string) $z['avatar_seed'] : substr((string) $z['user_id'], 0, 16),
            'punkte'      => (int) $z['punkte'],
            'spiele'      => (int) $z['spiele'],
            'gelistet'    => (bool) $z['gelistet'],
        ], $zeilen);
    }

    /**
     * Spielende des frühesten Spiels, das in eine Bestenliste eingehen kann (beendet,
     * abgerechnet, global gewertet). Begrenzt das Zurückblättern; null = noch keins.
     */
    public function findeFruehestesGewertetesSpielende(): ?\DateTimeImmutable
    {
        $wert = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT MIN(s.beendet_am)
             FROM spiele s
             WHERE s.status = ?
               AND s.wertung_details IS NOT NULL
               AND s.global_gewertet = true',
            [SpielStatus::BEENDET->value],
        )->fetchOne();

        return $wert ? new \DateTimeImmutable((string) $wert, new \DateTimeZone('UTC')) : null;
    }

    /**
     * Tischliste: Punkte aller beendeten Spiele an einem Tisch, je Mensch (user_id)
     * bzw. je Bot (bot_kennung = ID seines Tischplatzes). Abgebrochene und nach abgelehnter
     * Armut neu gegebene Spiele (ohne Abrechnung) zählen nicht.
     *
     * @return list<array{user_id: ?string, bot_kennung: ?string, punkte: int, spiele: int}>
     */
    public function findeTischKonten(Tisch $tisch): array
    {
        $zeilen = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT st.user_id,
                    CASE WHEN st.ist_bot THEN st.bot_kennung END AS bot_kennung,
                    COALESCE(SUM(st.punkte_delta), 0)             AS punkte,
                    COUNT(st.id)                                  AS spiele
             FROM spiel_teilnehmer st
             JOIN spiele s ON s.id = st.spiel_id
             WHERE s.tisch_id = ?
               AND s.status   = ?
               AND s.wertung_details IS NOT NULL
             GROUP BY st.user_id, CASE WHEN st.ist_bot THEN st.bot_kennung END',
            [$tisch->getId()->toRfc4122(), SpielStatus::BEENDET->value],
        )->fetchAllAssociative();

        return array_map(static fn(array $z): array => [
            'user_id'     => $z['user_id'] !== null ? (string) $z['user_id'] : null,
            'bot_kennung' => $z['bot_kennung'] !== null ? (string) $z['bot_kennung'] : null,
            'punkte'      => (int) $z['punkte'],
            'spiele'      => (int) $z['spiele'],
        ], $zeilen);
    }
}
