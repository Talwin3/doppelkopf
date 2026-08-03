<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Simulation\EngineStrategie;
use App\Domain\Doppelkopf\Simulation\SimulierteStrategie;
use App\Domain\Doppelkopf\Simulation\TurnierLauf;
use App\Enum\BotStaerke;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Self-Play-Benchmark: lässt zwei Bot-Strategien headless gegeneinander spielen und
 * misst den Unterschied in Turnierpunkten je Spieler und Partie.
 *
 * Jedes Blatt wird gespiegelt gespielt (beide Strategien bekommen dieselben Karten auf
 * denselben Sitzen), deshalb ist das Ergebnis frei vom Kartenglück. Ohne diese Messung
 * ist jede Änderung an den Engines geraten.
 *
 *   bin/console app:bot:turnier --a=profi --b=fortgeschritten -n 500
 *   bin/console app:bot:turnier --a=profi --b=profi --rollouts-a=300 -n 500
 */
#[AsCommand(
    name: 'app:bot:turnier',
    description: 'Self-Play-Benchmark: zwei Bot-Strategien gegeneinander messen.',
)]
final class BotTurnierCommand extends Command
{
    public function __construct(
        private readonly TurnierLauf $turnierLauf,
        private readonly BotHeuristik $anfaenger,
        private readonly FortgeschritteneHeuristik $fortgeschritten,
        private readonly ProfiHeuristik $profi,
        private readonly AnsageHeuristik $ansageHeuristik,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('spiele', null, InputOption::VALUE_REQUIRED, 'Anzahl der Blätter (jedes wird gespiegelt gespielt)', '200')
            ->addOption('a', null, InputOption::VALUE_REQUIRED, 'Strategie A: anfaenger|fortgeschritten|profi', 'profi')
            ->addOption('b', null, InputOption::VALUE_REQUIRED, 'Strategie B: anfaenger|fortgeschritten|profi', 'fortgeschritten')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Zufalls-Seed (gleicher Seed = gleiche Blätter)', '42')
            ->addOption('rollouts-a', null, InputOption::VALUE_REQUIRED, 'PIMC-Rollouts für A', '60')
            ->addOption('rollouts-b', null, InputOption::VALUE_REQUIRED, 'PIMC-Rollouts für B', '60')
            // Default bewusst hoch: greift das Zeitbudget, hängt die Spielstärke an der
            // CPU-Last und der Lauf wäre nicht mehr reproduzierbar. Gemessen wird die
            // Stärke bei einer festen Rollout-Zahl; der Produktiv-Deckel (0,8 s) ist eine
            // Frage des Spielflusses und lässt sich hier explizit nachstellen.
            ->addOption('zeitbudget-a', null, InputOption::VALUE_REQUIRED, 'PIMC-Zeitbudget für A in Sekunden', '999')
            ->addOption('zeitbudget-b', null, InputOption::VALUE_REQUIRED, 'PIMC-Zeitbudget für B in Sekunden', '999')
            ->addOption('ohne-ansagen-a', null, InputOption::VALUE_NONE, 'A macht keine Ansagen (misst den Wert der Ansage-Heuristik)')
            ->addOption('ohne-ansagen-b', null, InputOption::VALUE_NONE, 'B macht keine Ansagen')
            ->addOption('ohne-neuner', null, InputOption::VALUE_NONE, 'Mit 40-Karten-Blatt spielen')
            ->addOption('zweite-dulle', null, InputOption::VALUE_NONE, 'Zweite Dulle sticht');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $anzahl = max(1, (int) $input->getOption('spiele'));
        $seed   = (int) $input->getOption('seed');

        try {
            $a = $this->baueStrategie(
                (string) $input->getOption('a'),
                (int) $input->getOption('rollouts-a'),
                (float) $input->getOption('zeitbudget-a'),
                !$input->getOption('ohne-ansagen-a'),
            );
            $b = $this->baueStrategie(
                (string) $input->getOption('b'),
                (int) $input->getOption('rollouts-b'),
                (float) $input->getOption('zeitbudget-b'),
                !$input->getOption('ohne-ansagen-b'),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $regelEinstellungen = [
            'ohne_neuner'         => (bool) $input->getOption('ohne-neuner'),
            'zweite_dulle_sticht' => (bool) $input->getOption('zweite-dulle'),
        ];

        $io->title('Bot-Turnier');
        $io->definitionList(
            ['A' => $a->name()],
            ['B' => $b->name()],
            ['Blätter' => (string) $anzahl . ' (je 2 Partien, gespiegelt)'],
            ['Seed' => (string) $seed],
            ['Regeln' => sprintf(
                'ohne Neuner: %s, zweite Dulle sticht: %s',
                $regelEinstellungen['ohne_neuner'] ? 'ja' : 'nein',
                $regelEinstellungen['zweite_dulle_sticht'] ? 'ja' : 'nein',
            )],
        );

        $fortschritt = $io->createProgressBar($anzahl);
        $fortschritt->start();

        $ergebnis = $this->turnierLauf->spiele(
            $a,
            $b,
            $anzahl,
            $seed,
            $regelEinstellungen,
            static fn(int $blatt) => $fortschritt->setProgress($blatt),
        );

        $fortschritt->finish();
        $io->newLine(2);

        $mittel     = $ergebnis->mittelwert();
        $halbbreite = $ergebnis->konfidenzHalbbreite();

        $io->table(
            ['Kennzahl', 'Wert'],
            [
                ['gewertete Blätter', (string) $ergebnis->anzahlBlaetter()],
                ['übersprungen (stille Hochzeit)', (string) $ergebnis->uebersprungeneBlaetter],
                ['Ø Punkte/Spieler/Partie (A − B)', sprintf('%+.4f', $mittel)],
                ['95-%-Konfidenzintervall', sprintf('%+.4f … %+.4f', $mittel - $halbbreite, $mittel + $halbbreite)],
                ['Standardabweichung', sprintf('%.4f', $ergebnis->standardabweichung())],
                ['Ansagen A / B', sprintf('%d / %d', $ergebnis->ansagenA, $ergebnis->ansagenB)],
                ['Dauer', sprintf('%.1f s (%.3f s/Partie)', $ergebnis->dauerSek, $ergebnis->dauerSek / max(1, $ergebnis->anzahlBlaetter() * 2))],
            ],
        );

        if ($ergebnis->anzahlBlaetter() === 0) {
            $io->warning('Keine Blätter gewertet.');

            return Command::SUCCESS;
        }

        if ($ergebnis->signifikant()) {
            $besser = $mittel > 0 ? $ergebnis->nameA : $ergebnis->nameB;
            $io->success(sprintf(
                '%s ist signifikant stärker (%+.3f Punkte je Spieler und Partie).',
                $besser,
                abs($mittel),
            ));
        } else {
            $io->warning('Kein signifikanter Unterschied auf dem 95-%-Niveau.');
        }

        foreach ([0.5, 0.2, 0.1] as $effekt) {
            $noetig = $ergebnis->benoetigteBlaetter($effekt);
            if ($noetig !== null) {
                $io->writeln(sprintf(
                    '  Für den Nachweis von %.1f Punkten Unterschied wären ~%s Blätter nötig.',
                    $effekt,
                    number_format($noetig, 0, ',', '.'),
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function baueStrategie(string $name, int $rollouts, float $zeitbudget, bool $ansagenAktiv): SimulierteStrategie
    {
        $staerke = BotStaerke::tryFrom(strtolower($name))
            ?? throw new \InvalidArgumentException(sprintf(
                'Unbekannte Strategie "%s". Erlaubt: %s',
                $name,
                implode(', ', array_map(fn(BotStaerke $s) => $s->value, BotStaerke::cases())),
            ));

        return new EngineStrategie(
            $staerke,
            $this->anfaenger,
            $this->fortgeschritten,
            $this->profi,
            $this->ansageHeuristik,
            max(1, $rollouts),
            max(0.001, $zeitbudget),
            $ansagenAktiv,
        );
    }
}
