<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\SystemEinstellung;
use App\Repository\SystemEinstellungRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Liest SystemEinstellungen mit kurzem Cache (60 s) um DB-Last bei vielen Tischen zu minimieren.
 * Schreiben erfolgt direkt ohne Cache-Invalidierung — der 60-s-TTL reicht aus.
 */
final class SystemEinstellungService
{
    /** Standard-Werte, falls kein DB-Eintrag vorhanden. */
    private const DEFAULTS = [
        'pause_zwischen_spielen_sekunden' => '5',
        'disconnect_timeout_sekunden'     => '30',
        'bot_avatar_stil'                 => 'bottts',
        'bot_disconnect_staerke'          => 'anfaenger',
        'chat_schnellnachrichten'         => "Gut gespielt!\nGlückwunsch!\nSchönes Spiel!\nViel Glück!\nTut mir leid!\nGute Nacht!",
    ];

    public function __construct(
        private readonly SystemEinstellungRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    public function getInt(string $schluessel): int
    {
        return (int) $this->get($schluessel);
    }

    public function get(string $schluessel): string
    {
        $cacheKey = 'sys_einstellung_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $schluessel);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($schluessel) {
            $item->expiresAfter(60);

            $einstellung = $this->repo->findBySchluessel($schluessel);
            if ($einstellung !== null) {
                return $einstellung->getWert();
            }

            return self::DEFAULTS[$schluessel] ?? '';
        });
    }

    public function set(string $schluessel, string $wert): void
    {
        $einstellung = $this->repo->findBySchluessel($schluessel);

        if ($einstellung === null) {
            $einstellung = new SystemEinstellung($schluessel, $wert);
            $this->em->persist($einstellung);
        } else {
            $einstellung->setWert($wert);
        }

        $this->em->flush();

        $cacheKey = 'sys_einstellung_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $schluessel);
        $this->cache->delete($cacheKey);
    }

    /** Initialisiert alle Standard-Einträge in der DB wenn noch nicht vorhanden. */
    public function initialisiereDefaults(): void
    {
        foreach (self::DEFAULTS as $schluessel => $defaultWert) {
            if ($this->repo->findBySchluessel($schluessel) === null) {
                $beschreibungen = [
                    'pause_zwischen_spielen_sekunden' => 'Wartezeit in Sekunden zwischen zwei Spielen (Auto-Start)',
                    'disconnect_timeout_sekunden'     => 'Sekunden bis nach einem Disconnect ein Bot übernimmt',
                    'bot_avatar_stil'                 => 'Avatar-Stil für Bots (z. B. bottts, avataaars, lorelei)',
                    'bot_disconnect_staerke'          => 'Spielstärke des Bots, der bei einem Disconnect für einen Menschen übernimmt',
                    'chat_schnellnachrichten'         => 'Vordefinierte Schnell-Chatnachrichten (eine pro Zeile), die allen Spielern zur Auswahl stehen',
                ];

                $einstellung = new SystemEinstellung(
                    $schluessel,
                    $defaultWert,
                    $beschreibungen[$schluessel] ?? null,
                );
                $this->em->persist($einstellung);
            }
        }
        $this->em->flush();
    }
}
