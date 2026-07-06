<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Domain\Doppelkopf\ValueObject\Karte;
use App\Entity\Spiel;
use App\Entity\SpielTeilnehmer;
use App\Enum\BotStaerke;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Strategie, nach der ein Bot seine Züge wählt. Jede Spielstärke ({@see BotStaerke})
 * wird durch genau eine Implementierung dieses Interfaces bedient; der
 * {@see BotStrategieProvider} bildet Stärke → Strategie ab.
 *
 * Das Tag sorgt dafür, dass alle Implementierungen automatisch beim
 * {@see BotStrategieProvider} eingesammelt werden (Attribut statt config, damit
 * die Verdrahtung mit dem versionierten Code reist).
 *
 * Vorerst deckt das Interface die Kartenwahl im laufenden Stichspiel ab. Die
 * Vorbehalts-, Ansage- und Armut-Entscheidungen liegen noch in ihren jeweiligen
 * Services; sie wandern in späteren Ausbaustufen (Fortgeschritten/Profi) als
 * weitere Methoden hierher, sobald sie strategieabhängig werden.
 */
#[AutoconfigureTag('app.bot_strategie')]
interface BotStrategie
{
    /** Für welche Spielstärke diese Strategie zuständig ist. */
    public function staerke(): BotStaerke;

    /**
     * Wählt aus den erlaubten Karten die zu spielende aus.
     *
     * @param Karte[] $erlaubte Spielbare Karten (mindestens eine)
     */
    public function waehleKarte(Spiel $spiel, SpielTeilnehmer $teilnehmer, array $erlaubte): Karte;
}
