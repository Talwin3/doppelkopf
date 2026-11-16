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
        'bot_disconnect_staerke'          => 'profi',
        'bestenliste_min_spiele_monat'    => '50',
        'bestenliste_min_spiele_jahr'     => '150',
        'chat_schnellnachrichten'         => "Gut gespielt!\nGlückwunsch!\nSchönes Spiel!\nViel Glück!\nTut mir leid!\nGute Nacht!",
    ];

    /**
     * Einstellungen, die ganzzahlig sein müssen, mit ihrem kleinsten erlaubten Wert.
     * Ein Disconnect-Timeout von 0 ließe Bots sofort übernehmen.
     */
    private const GANZZAHLIG_MIN = [
        'pause_zwischen_spielen_sekunden' => 0,
        'disconnect_timeout_sekunden'     => 5,
        'bestenliste_min_spiele_monat'    => 0,
        'bestenliste_min_spiele_jahr'     => 0,
    ];

    /** Höchstwert für ganzzahlige Einstellungen – schützt vor Überlauf beim int-Cast. */
    private const GANZZAHLIG_MAX = 1_000_000;

    public function __construct(
        private readonly SystemEinstellungRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Prüft einen Wert vor dem Speichern. Liefert eine Fehlermeldung oder null, wenn
     * der Wert zulässig ist.
     */
    public function pruefeWert(string $schluessel, string $wert): ?string
    {
        if (!array_key_exists($schluessel, self::GANZZAHLIG_MIN)) {
            return null;
        }

        $min = self::GANZZAHLIG_MIN[$schluessel];
        if (!ctype_digit($wert) || (int) $wert < $min || (int) $wert > self::GANZZAHLIG_MAX) {
            return sprintf('Bitte eine ganze Zahl zwischen %d und %d eingeben.', $min, self::GANZZAHLIG_MAX);
        }

        return null;
    }

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
                    'bestenliste_min_spiele_monat'    => 'Mindestanzahl gewerteter Spiele einer Kategorie im Monat, ab der man in der Monatsliste erscheint',
                    'bestenliste_min_spiele_jahr'     => 'Mindestanzahl gewerteter Spiele einer Kategorie im Jahr, ab der man in der Jahresliste erscheint',
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
