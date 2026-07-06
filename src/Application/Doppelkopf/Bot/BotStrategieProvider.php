<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf\Bot;

use App\Enum\BotStaerke;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Bildet eine {@see BotStaerke} auf die zuständige {@see BotStrategie} ab.
 *
 * Alle Strategien werden über das Tag `app.bot_strategie` eingesammelt (siehe
 * config/services.yaml) und nach ihrer Stärke indiziert. Ist für eine Stärke
 * (noch) keine Strategie registriert, fällt der Provider auf die Anfänger-Stufe
 * zurück, damit nie ein Bot ohne Zuglogik dasteht.
 */
final class BotStrategieProvider
{
    /** @var array<string, BotStrategie> */
    private array $nachStaerke = [];

    /**
     * @param iterable<BotStrategie> $strategien
     */
    public function __construct(
        #[AutowireIterator('app.bot_strategie')]
        iterable $strategien,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($strategien as $strategie) {
            $this->nachStaerke[$strategie->staerke()->value] = $strategie;
        }
    }

    public function fuer(BotStaerke $staerke): BotStrategie
    {
        if (isset($this->nachStaerke[$staerke->value])) {
            return $this->nachStaerke[$staerke->value];
        }

        $fallback = BotStaerke::default();
        if (!isset($this->nachStaerke[$fallback->value])) {
            throw new \LogicException('Es ist keine Bot-Strategie registriert – Container fehlkonfiguriert.');
        }

        $this->logger->warning('Keine Bot-Strategie für Stärke {staerke}; Fallback auf {fallback}.', [
            'staerke'  => $staerke->value,
            'fallback' => $fallback->value,
        ]);

        return $this->nachStaerke[$fallback->value];
    }
}
