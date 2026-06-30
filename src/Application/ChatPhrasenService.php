<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\User;

/**
 * Verwaltet vordefinierte Schnell-Chatnachrichten: die vom Admin gepflegte
 * Standardliste (SystemEinstellung) und die persönlichen Phrasen eines Nutzers.
 * Beim Anzeigen im Chat werden beide zusammengeführt (Admin-Defaults zuerst,
 * danach die eigenen), dedupliziert und begrenzt.
 */
final class ChatPhrasenService
{
    public const ADMIN_SCHLUESSEL = 'chat_schnellnachrichten';

    /** Maximale Anzahl persönlicher Phrasen pro Nutzer. */
    public const MAX_PERSOENLICH = 12;

    /** Maximale Länge einer einzelnen Phrase (Zeichen). */
    public const MAX_LAENGE = 80;

    /** Obergrenze der im Chat angezeigten Chips (Admin + eigene zusammen). */
    private const MAX_GESAMT = 24;

    public function __construct(
        private readonly SystemEinstellungService $einstellungService,
    ) {}

    /**
     * Zerlegt rohen Textarea-Inhalt (eine Phrase pro Zeile) in eine bereinigte
     * Liste: getrimmt, leere Zeilen raus, gekürzt, dedupliziert.
     *
     * @return string[]
     */
    public function parseListe(string $roh, int $maxAnzahl = self::MAX_PERSOENLICH): array
    {
        $phrasen = [];
        foreach (preg_split('/\R/u', $roh) ?: [] as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }
            if (mb_strlen($zeile) > self::MAX_LAENGE) {
                $zeile = mb_substr($zeile, 0, self::MAX_LAENGE);
            }
            if (!in_array($zeile, $phrasen, true)) {
                $phrasen[] = $zeile;
            }
            if (count($phrasen) >= $maxAnzahl) {
                break;
            }
        }

        return $phrasen;
    }

    /**
     * Die vom Admin gepflegte Standardliste.
     *
     * @return string[]
     */
    public function adminDefaults(): array
    {
        return $this->parseListe($this->einstellungService->get(self::ADMIN_SCHLUESSEL), self::MAX_GESAMT);
    }

    /**
     * Im Chat anzubietende Phrasen für einen Nutzer: Admin-Defaults zuerst,
     * danach die persönlichen, dedupliziert und auf MAX_GESAMT begrenzt.
     *
     * @return string[]
     */
    public function fuerUser(User $user): array
    {
        $phrasen = [];
        foreach ([...$this->adminDefaults(), ...$user->getChatPhrasen()] as $phrase) {
            if (!in_array($phrase, $phrasen, true)) {
                $phrasen[] = $phrase;
            }
            if (count($phrasen) >= self::MAX_GESAMT) {
                break;
            }
        }

        return $phrasen;
    }
}
