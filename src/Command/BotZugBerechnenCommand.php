<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Doppelkopf\BotZugService;
use App\Application\Doppelkopf\SpielStartService;
use App\Application\SystemEinstellungService;
use App\Entity\Tisch;
use App\Enum\SpielStatus;
use App\Infrastructure\Logger\BotApiLogger;
use App\Repository\SpielRepository;
use App\Repository\TischRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tisch-Worker: Bot-Züge, Auto-Start, Tisch-Löschung.
 *
 * Ausführung als Docker-Worker-Service (Dauerschleife):
 *   bin/console app:bot:spielzuge
 *
 * Einmalige Ausführung (z. B. für Tests):
 *   bin/console app:bot:spielzuge --einmalig
 */
#[AsCommand(
    name: 'app:bot:spielzuge',
    description: 'Tisch-Worker: Bot-Züge berechnen, Auto-Start, menschenlose Tische löschen.',
)]
class BotZugBerechnenCommand extends Command
{
    private const BOT_TIMEOUT_SEK   = 3;
    private const SCHLAF_US         = 2_000_000; // 2 Sekunden

    public function __construct(
        private readonly SpielRepository $spielRepo,
        private readonly TischRepository $tischRepo,
        private readonly BotZugService $botZugService,
        private readonly SpielStartService $spielStartService,
        private readonly SystemEinstellungService $einstellungService,
        private readonly EntityManagerInterface $em,
        private readonly BotApiLogger $logger,
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
            '<info>Tisch-Worker gestartet</info> (Modus: %s)',
            $einmalig ? 'einmalig' : 'Dauerschleife',
        ));

        do {
            $this->pruefeZugTimeouts($output);
            $this->pruefeAutoStart($output);
            $this->pruefeMenschenloseTische($output);

            if (!$einmalig) {
                usleep(self::SCHLAF_US);
            }
        } while (!$einmalig);

        return Command::SUCCESS;
    }

    private function pruefeZugTimeouts(OutputInterface $output): void
    {
        $humanTimeout = $this->einstellungService->getInt('disconnect_timeout_sekunden');
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
            $benoetigtSek = $istBot ? self::BOT_TIMEOUT_SEK : $humanTimeout;

            if ($wartezeit < $benoetigtSek) {
                continue;
            }

            $grund = $istBot ? 'Bot-Spieler' : sprintf('Disconnect-Timeout nach %ds', $wartezeit);
            $output->writeln(sprintf(
                '  Spiel %s – Sitzplatz %d spielt (%s)',
                substr((string) $spiel->getId(), 0, 8),
                $sitzplatz,
                $grund,
            ));

            try {
                $this->botZugService->spielenFuerSitzplatz($spiel, $sitzplatz);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  <error>Bot-Zug Fehler: %s</error>', $e->getMessage()));
            }
        }
    }

    private function pruefeAutoStart(OutputInterface $output): void
    {
        $tische = $this->tischRepo->findMitFaelligemAutoStart();

        foreach ($tische as $tisch) {
            $output->writeln(sprintf(
                '  Auto-Start Tisch %s',
                substr((string) $tisch->getId(), 0, 8),
            ));

            // Countdown zurücksetzen bevor Spiel startet
            $tisch->setNaechsterSpielstartAm(null);
            $this->em->flush();

            try {
                if ($tisch->anzahlAktiveSpieler() === 4) {
                    $this->spielStartService->starten($tisch);
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  <error>Auto-Start Fehler: %s</error>', $e->getMessage()));
            }
        }
    }

    private function pruefeMenschenloseTische(OutputInterface $output): void
    {
        $loeschenNachMinuten = $this->einstellungService->getInt('tisch_loeschen_nach_minuten');
        $tische = $this->tischRepo->findMenschenloseFuerLoeschung($loeschenNachMinuten);

        foreach ($tische as $tisch) {
            // Nicht löschen wenn gerade ein Spiel läuft (Bots spielen durch)
            $laufendesSpiel = $this->spielRepo->findLaufendesSpielFuerTisch($tisch);
            if ($laufendesSpiel !== null) {
                continue;
            }

            $output->writeln(sprintf(
                '  Lösche menschenlosen Tisch %s (seit %s)',
                substr((string) $tisch->getId(), 0, 8),
                $tisch->getMenschenloseSeitAm()?->format('H:i:s') ?? '-',
            ));

            $this->em->remove($tisch);
            $this->em->flush();
        }
    }
}
