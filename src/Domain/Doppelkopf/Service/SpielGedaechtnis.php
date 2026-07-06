<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Enum\Kartenfarbe;

/**
 * Kartengedächtnis eines Bots für ein laufendes Spiel. Reines, DB-freies Value Object:
 * es leitet aus dem bisherigen Spielverlauf ab, welche Karten noch im Spiel sind
 * ("ungesehen" = weder gespielt noch auf der eigenen Hand) und welche Spieler in
 * welcher Farbe frei sind (haben eine Farbe nicht bedient → können dort stechen/abwerfen).
 *
 * Wird von {@see FortgeschritteneHeuristik} genutzt (Stufe BotStaerke::FORTGESCHRITTEN).
 */
final class SpielGedaechtnis
{
    /** Marker im Frei-Register für "hat Trumpf nicht bedient". */
    private const TRUMPF_FREI = '__TRUMPF__';

    /**
     * @param Karte[]                       $ungesehene Karten, die weder gespielt noch auf eigener Hand sind (bei den Gegnern/Partner).
     * @param array<int, array<string,bool>> $freiRegister Sitzplatz → Menge freier Kategorien (Kartenfarbe->value oder TRUMPF_FREI).
     */
    private function __construct(
        private readonly array $ungesehene,
        private readonly array $freiRegister,
    ) {}

    /**
     * Baut das Gedächtnis aus dem geordneten Spielverlauf.
     *
     * @param list<array{sitzplatz:int, stichNr:int, position:int, karte:Karte}> $ereignisse Alle bisher gespielten Karten (nach Stich/Position geordnet).
     * @param Karte[] $universum   Alle Karten dieses Spiels (Vereinigung aller Starthände).
     * @param Karte[] $eigeneHand  Aktuelle Handkarten des Bots.
     */
    public static function ausHistorie(
        array $ereignisse,
        array $universum,
        array $eigeneHand,
        TrumpfOrdnung $ordnung,
    ): self {
        // Ungesehene Karten = Universum minus bereits gespielt minus eigene Hand.
        $gesehen = [];
        foreach ($ereignisse as $e) {
            $gesehen[$e['karte']->id()] = true;
        }
        foreach ($eigeneHand as $k) {
            $gesehen[$k->id()] = true;
        }
        $ungesehene = array_values(array_filter($universum, fn(Karte $k) => !isset($gesehen[$k->id()])));

        // Frei-Register: pro Stich die geforderte Kategorie bestimmen und jeden
        // Spieler markieren, der sie nicht bedient hat.
        $stiche = [];
        foreach ($ereignisse as $e) {
            $stiche[$e['stichNr']][$e['position']] = $e;
        }

        $freiRegister = [];
        foreach ($stiche as $karten) {
            ksort($karten);
            $angespielt = ($karten[array_key_first($karten)] ?? null)['karte'] ?? null;
            if ($angespielt === null) {
                continue;
            }

            $gefordert = $ordnung->istTrumpf($angespielt)
                ? self::TRUMPF_FREI
                : $ordnung->fehlfarbe($angespielt)?->value;
            if ($gefordert === null) {
                continue;
            }

            foreach ($karten as $e) {
                $karte = $e['karte'];
                $bedient = $gefordert === self::TRUMPF_FREI
                    ? $ordnung->istTrumpf($karte)
                    : (!$ordnung->istTrumpf($karte) && $ordnung->fehlfarbe($karte)?->value === $gefordert);

                if (!$bedient) {
                    $freiRegister[$e['sitzplatz']][$gefordert] = true;
                }
            }
        }

        return new self($ungesehene, $freiRegister);
    }

    /** Karten, die noch bei anderen Spielern liegen (weder gespielt noch eigene Hand). */
    public function ungesehene(): array
    {
        return $this->ungesehene;
    }

    /**
     * Ist bekannt, dass dieser Sitzplatz in der Farbe frei ist (nicht mehr bedienen kann)?
     * $farbe = null prüft Trumpf-Freiheit.
     */
    public function istFrei(int $sitzplatz, ?Kartenfarbe $farbe): bool
    {
        $schluessel = $farbe?->value ?? self::TRUMPF_FREI;

        return isset($this->freiRegister[$sitzplatz][$schluessel]);
    }
}
