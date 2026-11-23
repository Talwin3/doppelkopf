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

    /**
     * Aggregierte Spielstatistik für einen User.
     *
     * @return array{spiele: int, siege: int, punkte: int}
     */
    public function findeUserStatistik(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row  = $conn->executeQuery(
            'SELECT COUNT(st.id)                            AS spiele,
                    COUNT(CASE WHEN st.gewonnen THEN 1 END) AS siege,
                    COALESCE(SUM(st.punkte_delta), 0)       AS punkte
             FROM spiel_teilnehmer st
             JOIN spiele s ON s.id = st.spiel_id
             WHERE st.user_id = ?
               AND st.ist_bot = false
               AND s.status   = ?
               AND s.wertung_details IS NOT NULL',
            [(string) $user->getId(), SpielStatus::BEENDET->value],
        )->fetchAssociative();

        return $row ?: ['spiele' => 0, 'siege' => 0, 'punkte' => 0];
    }

    /**
     * Punkte-Summe und Spiele je Kalendermonat der letzten $monate Monate.
     *
     * @return array<int, array{jahr: int, monat: int, punkte: int, spiele: int}>
     */
    public function findePunkteProMonat(User $user, int $monate = 6): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $von  = (new \DateTimeImmutable('first day of this month'))
            ->modify('-' . ($monate - 1) . ' months')
            ->setTime(0, 0, 0);

        return $conn->executeQuery(
            'SELECT EXTRACT(YEAR  FROM s.beendet_am)::int AS jahr,
                    EXTRACT(MONTH FROM s.beendet_am)::int AS monat,
                    COALESCE(SUM(st.punkte_delta), 0)     AS punkte,
                    COUNT(st.id)                           AS spiele
             FROM spiel_teilnehmer st
             JOIN spiele s ON s.id = st.spiel_id
             WHERE st.user_id = ?
               AND st.ist_bot = false
               AND s.status   = ?
               AND s.wertung_details IS NOT NULL
               AND s.beendet_am >= ?
             GROUP BY EXTRACT(YEAR FROM s.beendet_am), EXTRACT(MONTH FROM s.beendet_am)
             ORDER BY jahr ASC, monat ASC',
            [(string) $user->getId(), SpielStatus::BEENDET->value, $von->format('Y-m-d')],
        )->fetchAllAssociative();
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
