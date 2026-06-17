<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Entity\Spiel;
use App\Infrastructure\Logger\MercureLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SpielMercurePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureLogger $logger,
    ) {}

    public function kartenAusgeteilt(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'KARTEN_AUSGETEILT');
    }

    public function spielGestartet(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'SPIEL_GESTARTET');
    }

    public function spielAktualisiert(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'SPIEL_AKTUALISIERT');
    }

    public function spielBeendet(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'SPIEL_BEENDET');
    }

    public function tischZustandAktualisiert(Spiel $spiel): void
    {
        $this->publizieren($spiel, 'TISCH_ZUSTAND');
    }

    private function publizieren(Spiel $spiel, string $typ): void
    {
        $topic = $this->topic($spiel);
        try {
            $this->hub->publish(new Update(
                $topic,
                json_encode(['typ' => $typ, 'spielId' => (string) $spiel->getId()], JSON_THROW_ON_ERROR),
            ));
            $this->logger->updateVeroeffentlicht($topic, $typ);
        } catch (\Throwable $e) {
            $this->logger->veroeffentlichungFehlgeschlagen($topic, $e->getMessage());
        }
    }

    public function topic(Spiel $spiel): string
    {
        return 'https://doppelkopf.de/spiel/' . $spiel->getId();
    }
}
