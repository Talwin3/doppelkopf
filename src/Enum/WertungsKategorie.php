<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Punktekonto, auf das ein Spiel gebucht wird. Jeder Spieler hat je Kategorie ein
 * eigenes Konto, Monats- und Jahreslisten werden getrennt geführt.
 *
 * Die Kategorie folgt allein aus dem Regelwerk, das beim Spielstart galt:
 *  - {@see self::OHNE_NEUNER}: ohne Neuner, alle anderen Regeln beliebig
 *  - {@see self::DDV}: mit Neunern und exakt nach den DDV-Turnierspielregeln
 *  - {@see self::SONDERREGELN}: mit Neunern und mindestens einer Abweichung vom DDV
 */
enum WertungsKategorie: string
{
    case DDV          = 'DDV';
    case OHNE_NEUNER  = 'OHNE_NEUNER';
    case SONDERREGELN = 'SONDERREGELN';

    /**
     * Regelwerk nach den DDV-Turnierspielregeln (Stand 21.02.2026). Jede Abweichung
     * führt zu {@see self::SONDERREGELN}.
     *
     *  - Alle sieben Soli erlaubt (TSR 2.3).
     *  - Der Solist spielt NICHT selbst auf: Das tut er nur beim Pflichtsolo (TSR 4.2.2),
     *    beim Lustsolo spielt der linke Nachbar des Gebers auf (TSR 4.3.1). Pflichtsoli
     *    gibt es auf der Plattform nicht, jedes Solo ist ein Lustsolo.
     *  - Kein Schweinchen, keine Armut (kommt in der TSR nicht vor), bei zwei gleichen
     *    Karten sticht die zuerst gespielte, auch bei der Herz 10 (TSR 5.3).
     *
     * @var array<string, mixed>
     */
    public const DDV_REGELN = [
        'soli_erlaubt'        => ['SOLO_BUBEN', 'SOLO_DAMEN', 'SOLO_FLEISCHLOS', 'SOLO_KARO', 'SOLO_HERZ', 'SOLO_PIK', 'SOLO_KREUZ'],
        'solist_kommt_raus'   => false,
        'schweinchen'         => false,
        'superschweinchen'    => false,
        'ohne_neuner'         => false,
        'armut'               => false,
        'zweite_dulle_sticht' => false,
    ];

    /**
     * Schlüssel im Regelwerk-JSON, die nur die Darstellung betreffen und das Spiel
     * nicht verändern. Sie spielen für die Kategorie keine Rolle.
     */
    public const DARSTELLUNGS_SCHLUESSEL = ['augen_mouseover'];

    /**
     * Bestimmt die Kategorie eines Regelwerks.
     *
     * Ein Schlüssel, der weder in {@see self::DDV_REGELN} noch in
     * {@see self::DARSTELLUNGS_SCHLUESSEL} steht, ist eine noch nicht eingeordnete
     * neue Regel und zählt vorsichtshalber als Abweichung. Ein Test stellt sicher,
     * dass jede Regel des Tischs hier eingeordnet ist.
     *
     * @param array<string, mixed> $regeln
     */
    public static function fuerRegelwerk(array $regeln): self
    {
        if ((bool) ($regeln['ohne_neuner'] ?? false)) {
            return self::OHNE_NEUNER;
        }

        foreach (self::DDV_REGELN as $schluessel => $ddvWert) {
            $wert = $regeln[$schluessel] ?? $ddvWert;

            if ($schluessel === 'soli_erlaubt') {
                $erlaubt = is_array($wert) ? $wert : [];
                if (array_diff($ddvWert, $erlaubt) !== []) {
                    return self::SONDERREGELN;
                }
                continue;
            }

            if ((bool) $wert !== $ddvWert) {
                return self::SONDERREGELN;
            }
        }

        $unbekannt = array_diff(
            array_keys($regeln),
            array_keys(self::DDV_REGELN),
            self::DARSTELLUNGS_SCHLUESSEL,
        );

        return $unbekannt === [] ? self::DDV : self::SONDERREGELN;
    }

    public function label(): string
    {
        return match ($this) {
            self::DDV          => 'DDV',
            self::OHNE_NEUNER  => 'Ohne Neuner',
            self::SONDERREGELN => 'Sonderregeln',
        };
    }
}
