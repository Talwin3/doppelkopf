<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Enum\SpielVariante;
use App\Enum\Team;

/**
 * Bestimmt, welche Team-Zugehörigkeiten für einen Spieler **öffentlich bekannt** sind –
 * damit Bots keine verdeckte Partnerschaft ausnutzen ("nicht schummeln").
 *
 * Spiegelt exakt die Anzeige-Regel des Frontends ({@see templates/spieltisch/_tisch_bereich.html.twig}):
 *  - **Solo:** alle Parteien sofort bekannt (der Solist ist öffentlich).
 *  - **Hochzeit:** der Hochzeitsspieler ist sofort als RE bekannt; die übrigen erst,
 *    wenn die Hochzeit aufgelöst ist (Partner steht fest).
 *  - **Normalspiel:** ein Sitz ist bekannt, sobald er eine Kreuz-Dame gespielt hat,
 *    beide Kreuz-Damen gespielt wurden, oder der Sitz Re/Contra (o. ä.) angesagt hat.
 *  - Das **eigene** Team ist immer bekannt (man kennt die eigenen Karten).
 *
 * Reine Funktion ohne Datenbankzugriff; die Fakten sammelt
 * {@see \App\Application\Doppelkopf\Bot\OeffentlicheTeams}.
 */
final class TeamSichtbarkeit
{
    /**
     * @param array<int, ?Team> $teamsAlle              Sitzplatz → tatsächliches Team (Spiel-Wahrheit).
     * @param list<int>         $hochzeitspielerSitze   Sitze mit Vorbehalt HOCHZEIT.
     * @param list<int>         $kreuzDamenGespieltSitze Sitze, die eine Kreuz-Dame gespielt haben.
     * @param list<int>         $angesagtSitze          Sitze, die mindestens eine Ansage gemacht haben.
     * @return array<int, ?Team> Sitzplatz → bekanntes Team (null = für diesen Spieler noch verdeckt).
     */
    public static function bekannteTeams(
        array $teamsAlle,
        ?SpielVariante $variante,
        bool $hochzeitAufgeloest,
        array $hochzeitspielerSitze,
        array $kreuzDamenGespieltSitze,
        array $angesagtSitze,
        int $eigenerSitzplatz,
    ): array {
        $istSolo       = $variante !== null && str_starts_with($variante->value, 'SOLO_');
        $istHochzeit   = $variante === SpielVariante::HOCHZEIT;
        $beideKreuzDamen = count($kreuzDamenGespieltSitze) >= 2;

        $bekannt = [];
        foreach ($teamsAlle as $sitz => $team) {
            if ($sitz === $eigenerSitzplatz) {
                $sichtbar = true; // eigenes Team immer bekannt
            } elseif ($istSolo) {
                $sichtbar = true;
            } elseif ($istHochzeit) {
                $sichtbar = in_array($sitz, $hochzeitspielerSitze, true) || $hochzeitAufgeloest;
            } else {
                $sichtbar = in_array($sitz, $kreuzDamenGespieltSitze, true)
                    || $beideKreuzDamen
                    || in_array($sitz, $angesagtSitze, true);
            }

            $bekannt[$sitz] = $sichtbar ? $team : null;
        }

        return $bekannt;
    }
}
