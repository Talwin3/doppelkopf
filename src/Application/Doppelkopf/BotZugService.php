<?php

declare(strict_types=1);

namespace App\Application\Doppelkopf;

use App\Application\Doppelkopf\Bot\BotAnsage;
use App\Application\Doppelkopf\Bot\BotStrategieProvider;
use App\Entity\Spiel;
use App\Enum\BotStaerke;
use App\Enum\SpielStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BotZugService
{
    public function __construct(
        private readonly KarteAusspielenService $karteAusspielenService,
        private readonly VorbehaltService $vorbehaltService,
        private readonly ArmutService $armutService,
        private readonly BotStrategieProvider $strategieProvider,
        private readonly BotAnsage $botAnsage,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(BOT_API_URL)%')]
        private readonly string $botApiUrl,
        #[Autowire('%env(BOT_API_TOKEN)%')]
        private readonly string $botApiToken,
    ) {}

    /**
     * @param ?BotStaerke $staerkeOverride Erzwingt eine Spielstärke für die Kartenwahl (z. B. bei
     *                                     Disconnect-Übernahme eines Menschen); null = Stärke des Teilnehmers.
     */
    public function spielenFuerSitzplatz(Spiel $spiel, int $sitzplatz, ?BotStaerke $staerkeOverride = null): void
    {
        if ($spiel->getStatus() === SpielStatus::VORBEHALT) {
            $this->vorbehaltService->deklarierenAlsBot($spiel, $sitzplatz);
            return;
        }

        if ($spiel->getStatus() === SpielStatus::ARMUT_ANFRAGE) {
            $this->armutService->antwortenAlsBot($spiel, $sitzplatz);
            return;
        }

        // ARMUT_TAUSCH wird bereits in ArmutService.antwortenAlsBot() abgehandelt
        if ($spiel->getStatus() === SpielStatus::ARMUT_TAUSCH) {
            return;
        }

        $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);
        if ($teilnehmer === null) {
            return;
        }

        $erlaubte = $this->karteAusspielenService->erlaubteKarten($spiel, $teilnehmer);
        if (empty($erlaubte)) {
            return;
        }

        // Vor dem Zug ggf. Re/Contra ansagen – nur für echte Bots, nicht bei
        // Disconnect-Übernahme eines Menschen (keine bindende Ansage für Abwesende).
        if ($teilnehmer->isIstBot()) {
            $this->botAnsage->pruefen($spiel, $teilnehmer);
        }

        $staerke = $staerkeOverride ?? $teilnehmer->getBotStaerke();

        $gewaehlt = $this->botApiKarteWaehlen($spiel, $sitzplatz, $erlaubte)
            ?? $this->strategieProvider
                ->fuer($staerke)
                ->waehleKarte($spiel, $teilnehmer, $erlaubte)
                ->id();

        try {
            $this->karteAusspielenService->spielenAlsBot($spiel, $sitzplatz, $gewaehlt);
        } catch (\Throwable $e) {
            $this->logger->warning('BotZugService: Karte konnte nicht gespielt werden.', [
                'spiel_id' => (string) $spiel->getId(),
                'sitzplatz' => $sitzplatz,
                'karte_id'  => $gewaehlt,
                'fehler'    => $e->getMessage(),
            ]);
        }
    }

    /** Ruft den externen Bot-Service auf; gibt null zurück wenn nicht erreichbar. */
    private function botApiKarteWaehlen(Spiel $spiel, int $sitzplatz, array $erlaubte): ?string
    {
        if ($this->botApiUrl === '' || $this->botApiToken === '') {
            return null;
        }

        $payload = json_encode([
            'spiel_id'        => (string) $spiel->getId(),
            'tischplatz_id'   => (string) $sitzplatz,
            'erlaubte_karten' => array_map(fn($k) => $k->id(), $erlaubte),
            'spielzustand'    => [],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$this->botApiToken}",
                'content'       => $payload,
                'timeout'       => 3,
                'ignore_errors' => true,
            ],
        ]);

        $antwort = @file_get_contents($this->botApiUrl . '/spielzug-anfrage', false, $ctx);
        if ($antwort === false) {
            return null;
        }

        $daten = json_decode($antwort, true);
        $karte = $daten['karte'] ?? null;

        // Sicherstellen dass die zurückgegebene Karte tatsächlich erlaubt ist
        $erlaubteIds = array_map(fn($k) => $k->id(), $erlaubte);
        if (!in_array($karte, $erlaubteIds, true)) {
            return null;
        }

        return $karte;
    }
}
