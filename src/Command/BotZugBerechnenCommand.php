<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Doppelkopf\BotZugService;
use App\Enum\SpielStatus;
use App\Repository\SpielRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Erkennt Spielzug-Timeouts und lässt den Bot für inaktive Spieler einspringen.
 *
 * Ausführung als Docker-Worker-Service (Dauerschleife):
 *   bin/console app:bot:spielzuge
 *
 * Einmalige Ausführung (z. B. für Tests):
 *   bin/console app:bot:spielzuge --einmalig
 */
#[AsCommand(
    name: 'app:bot:spielzuge',
    description: 'Bot-Züge berechnen: erkennt Timeouts und spielt für inaktive Spieler.',
)]
class BotZugBerechnenCommand extends Command
{
    /** Bot-Spieler reagieren nach dieser Zeit (Sekunden). */
    private const BOT_TIMEOUT_SEK = 3;

    /** Menschliche Spieler werden nach dieser Zeit vom Bot übernommen. */
    private const HUMAN_TIMEOUT_SEK = 30;

    /** Wartezeit zwischen Prüfungen im Loop-Modus (Mikrosekunden). */
    private const SCHLAF_US = 2_000_000; // 2 Sekunden

    public function __construct(
        private readonly SpielRepository $spielRepo,
        private readonly BotZugService $botZugService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('einmalig', null, InputOption::VALUE_NONE, 'Nur einmal prüfen statt in Dauerschleife laufen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $einmalig = $input->getOption('einmalig');
        $output->writeln(sprintf(
            '<info>Bot-Worker gestartet</info> (Modus: %s)',
            $einmalig ? 'einmalig' : 'Dauerschleife',
        ));

        do {
            $this->pruefeTimeouts($output);

            if (!$einmalig) {
                usleep(self::SCHLAF_US);
            }
        } while (!$einmalig);

        return Command::SUCCESS;
    }

    private function pruefeTimeouts(OutputInterface $output): void
    {
        // Alle Spiele holen, die den kürzeren (Bot-)Timeout überschritten haben
        $spiele = $this->spielRepo->findMitZugTimeout(self::BOT_TIMEOUT_SEK);

        foreach ($spiele as $spiel) {
            $sitzplatz  = $spiel->getAktuellerSpielerSitzplatz();
            $teilnehmer = $spiel->getTeilnehmerBySitzplatz($sitzplatz);

            if ($teilnehmer === null) {
                continue;
            }

            $zeitpunkt = $spiel->getAktuellerZugBegannAm();
            if ($zeitpunkt === null) {
                continue;
            }

            $wartezeit = (new \DateTimeImmutable())->getTimestamp() - $zeitpunkt->getTimestamp();

            $istBot    = $teilnehmer->isIstBot();
            $benoetigtSek = $istBot ? self::BOT_TIMEOUT_SEK : self::HUMAN_TIMEOUT_SEK;

            if ($wartezeit < $benoetigtSek) {
                continue;
            }

            $grund = $istBot ? 'Bot-Spieler' : sprintf('Timeout nach %ds', $wartezeit);
            $output->writeln(sprintf(
                '  Spiel %s – Sitzplatz %d spielt (%s)',
                substr((string) $spiel->getId(), 0, 8),
                $sitzplatz,
                $grund,
            ));

            $this->logger->info('Bot übernimmt Zug.', [
                'spiel_id'  => (string) $spiel->getId(),
                'sitzplatz' => $sitzplatz,
                'ist_bot'   => $istBot,
                'wartezeit' => $wartezeit,
            ]);

            try {
                $this->botZugService->spielenFuerSitzplatz($spiel, $sitzplatz);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  <error>Fehler: %s</error>', $e->getMessage()));
                $this->logger->error('Bot-Zug fehlgeschlagen.', [
                    'spiel_id' => (string) $spiel->getId(),
                    'fehler'   => $e->getMessage(),
                ]);
            }
        }
    }
}
