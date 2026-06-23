<?php

declare(strict_types=1);

namespace App\Domain\Doppelkopf\Service;

/**
 * Liefert zufällige deutsche Vornamen für Bot-Spieler.
 *
 * Die Liste ist eine kuratierte Auswahl gebräuchlicher deutscher Vornamen
 * (gemeinfrei – Namen selbst unterliegen keinem Urheberrecht) und kommt ohne
 * externe Abhängigkeit aus. Reine Funktion ohne DB-Zugriff, daher gut testbar.
 */
final class BotNamenProvider
{
    /** @var string[] */
    private const NAMEN = [
        // weiblich
        'Anna', 'Bärbel', 'Brigitte', 'Christa', 'Claudia', 'Doris', 'Elke',
        'Erika', 'Frieda', 'Gabi', 'Gerda', 'Gisela', 'Hannelore', 'Heike',
        'Helga', 'Hilde', 'Ilse', 'Inge', 'Irmgard', 'Karin', 'Klara',
        'Lotte', 'Margot', 'Marianne', 'Monika', 'Petra', 'Renate', 'Rosi',
        'Sabine', 'Sieglinde', 'Traudel', 'Ursula', 'Ute', 'Waltraud',
        // männlich
        'Alfons', 'Bernd', 'Bruno', 'Detlef', 'Dieter', 'Eberhard',
        'Egon', 'Erwin', 'Franz', 'Friedrich', 'Fritz', 'Günther', 'Hans',
        'Heinz', 'Helmut', 'Herbert', 'Horst', 'Jürgen', 'Karl', 'Klaus',
        'Kurt', 'Lothar', 'Ludwig', 'Manfred', 'Norbert', 'Otto', 'Reiner',
        'Rudi', 'Siegfried', 'Theo', 'Udo', 'Volker', 'Werner', 'Wilhelm',
    ];

    /**
     * Liefert einen zufälligen Vornamen, der möglichst nicht in $bereitsVergeben
     * enthalten ist (z. B. Namen anderer Bots am selben Tisch). Sind alle Namen
     * vergeben, wird dennoch einer zurückgegeben (Kollision wird in Kauf genommen).
     *
     * @param string[] $bereitsVergeben
     */
    public function zufaelligerName(array $bereitsVergeben = []): string
    {
        $verfuegbar = array_values(array_diff(self::NAMEN, $bereitsVergeben));

        if ($verfuegbar === []) {
            $verfuegbar = self::NAMEN;
        }

        return $verfuegbar[array_rand($verfuegbar)];
    }
}
