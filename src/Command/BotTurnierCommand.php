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
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\FortgeschritteneRegeln;
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
            ->addOption('regeln-a', null, InputOption::VALUE_REQUIRED, 'Aktive FORTGESCHRITTEN-Regeln für A: alle|keine|"a,b"|"-a,-b"', 'alle')
            ->addOption('regeln-b', null, InputOption::VALUE_REQUIRED, 'Aktive FORTGESCHRITTEN-Regeln für B', 'alle')
            ->addOption('sicherheit-a', null, InputOption::VALUE_REQUIRED, 'A hält einen Stich ab dieser Wahrscheinlichkeit für sicher (1 = nur bei Gewissheit)')
            ->addOption('sicherheit-b', null, InputOption::VALUE_REQUIRED, 'dito für B')
            ->addOption('ansage-truempfe-a', null, InputOption::VALUE_REQUIRED, 'Mindest-Trümpfe für A als "re,contra" (z. B. "7,8")')
            ->addOption('ansage-truempfe-b', null, InputOption::VALUE_REQUIRED, 'dito für B')
            ->addOption('absage-truempfe-a', null, InputOption::VALUE_REQUIRED, 'Mindest-Trümpfe für A je Absage als "90,60,30,schwarz" (z. B. "10,11,12,13"); ohne Angabe keine Absagen')
            ->addOption('absage-truempfe-b', null, InputOption::VALUE_REQUIRED, 'dito für B')
            ->addOption('frueh-ansagen-a', null, InputOption::VALUE_NONE, 'A sagt zum frühestmöglichen statt zum letztmöglichen Zeitpunkt an (altes Verhalten)')
            ->addOption('frueh-ansagen-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('starthand-a', null, InputOption::VALUE_NONE, 'A bewertet für die Ansage die ausgeteilte Hand statt des Restblatts')
            ->addOption('starthand-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('ansage-augen-a', null, InputOption::VALUE_REQUIRED, 'A entscheidet die Ansage über die Blattbewertung in Augen: "re,contra" (z. B. "75,85")')
            ->addOption('ansage-augen-b', null, InputOption::VALUE_REQUIRED, 'dito für B')
            ->addOption('ohne-erspielte-a', null, InputOption::VALUE_NONE, 'A zählt die schon erspielten Team-Augen NICHT zur Blattbewertung (bewertet nur die Karten)')
            ->addOption('ohne-erspielte-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('profi-punkte-a', null, InputOption::VALUE_NONE, 'A bewertet die PIMC-Welten nach Turnierpunkten (v = 500*s + e) statt nach Ø-Augen')
            ->addOption('profi-punkte-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('profi-2gegen2-a', null, InputOption::VALUE_NONE, 'A verwirft Welten mit stiller Hochzeit (1 gegen 3) bei der Determinisierung')
            ->addOption('profi-2gegen2-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('regeln-liste', null, InputOption::VALUE_NONE, 'Nur die bekannten FORTGESCHRITTEN-Regeln auflisten und beenden')
            ->addOption('ohne-neuner', null, InputOption::VALUE_NONE, 'Mit 40-Karten-Blatt spielen')
            ->addOption('zweite-dulle', null, InputOption::VALUE_NONE, 'Zweite Dulle sticht');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('regeln-liste')) {
            $io->title('FORTGESCHRITTEN-Regeln');
            $io->table(
                ['Name', 'Wirkung'],
                array_map(
                    static fn(string $name, string $text) => [$name, $text],
                    array_keys(FortgeschritteneRegeln::BESCHREIBUNGEN),
                    array_values(FortgeschritteneRegeln::BESCHREIBUNGEN),
                ),
            );
            $io->writeln('Mit <info>--regeln-a=keine</info> spielt die Engine exakt wie ANFAENGER.');

            return Command::SUCCESS;
        }

        $anzahl = max(1, (int) $input->getOption('spiele'));
        $seed   = (int) $input->getOption('seed');

        try {
            $a = $this->baueStrategie(
                (string) $input->getOption('a'),
                (int) $input->getOption('rollouts-a'),
                (float) $input->getOption('zeitbudget-a'),
                !$input->getOption('ohne-ansagen-a'),
                (string) $input->getOption('regeln-a'),
                $input->getOption('sicherheit-a'),
                $input->getOption('ansage-truempfe-a'),
                $input->getOption('ansage-augen-a'),
                $input->getOption('absage-truempfe-a'),
                !$input->getOption('frueh-ansagen-a'),
                !$input->getOption('starthand-a'),
                !$input->getOption('ohne-erspielte-a'),
                (bool) $input->getOption('profi-punkte-a'),
                (bool) $input->getOption('profi-2gegen2-a'),
            );
            $b = $this->baueStrategie(
                (string) $input->getOption('b'),
                (int) $input->getOption('rollouts-b'),
                (float) $input->getOption('zeitbudget-b'),
                !$input->getOption('ohne-ansagen-b'),
                (string) $input->getOption('regeln-b'),
                $input->getOption('sicherheit-b'),
                $input->getOption('ansage-truempfe-b'),
                $input->getOption('ansage-augen-b'),
                $input->getOption('absage-truempfe-b'),
                !$input->getOption('frueh-ansagen-b'),
                !$input->getOption('starthand-b'),
                !$input->getOption('ohne-erspielte-b'),
                (bool) $input->getOption('profi-punkte-b'),
                (bool) $input->getOption('profi-2gegen2-b'),
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

    private function baueStrategie(
        string $name,
        int $rollouts,
        float $zeitbudget,
        bool $ansagenAktiv,
        string $regelListe,
        ?string $sicherheit,
        ?string $ansageTruempfe,
        ?string $ansageAugen,
        ?string $absageTruempfe,
        bool $letztmoeglich,
        bool $bewerteRestblatt,
        bool $mitErspieltenAugen,
        bool $punkteBewertung,
        bool $nurZweiGegenZwei,
    ): SimulierteStrategie {
        $staerke = BotStaerke::tryFrom(strtolower($name))
            ?? throw new \InvalidArgumentException(sprintf(
                'Unbekannte Strategie "%s". Erlaubt: %s',
                $name,
                implode(', ', array_map(fn(BotStaerke $s) => $s->value, BotStaerke::cases())),
            ));

        $regeln = FortgeschritteneRegeln::ausListe($regelListe);
        if ($sicherheit !== null && $sicherheit !== '') {
            $regeln = $regeln->mitSicherheitsSchwelle((float) $sicherheit);
        }

        $re      = 7;
        $contra  = 8;
        $absagen = [];
        if ($ansageTruempfe !== null && $ansageTruempfe !== '') {
            $werte = array_map('intval', array_map('trim', explode(',', $ansageTruempfe)));
            if (count($werte) !== 2) {
                throw new \InvalidArgumentException('Ansage-Trümpfe erwarten zwei Werte: "re,contra".');
            }
            [$re, $contra] = $werte;
        }
        if ($absageTruempfe !== null && $absageTruempfe !== '') {
            $werte = array_map('intval', array_map('trim', explode(',', $absageTruempfe)));
            if (count($werte) !== count(AnsageSchwellen::ABSAGE_KETTE)) {
                throw new \InvalidArgumentException(sprintf(
                    'Absage-Trümpfe erwarten %d Werte (keine 90, 60, 30, schwarz).',
                    count(AnsageSchwellen::ABSAGE_KETTE),
                ));
            }
            foreach (AnsageSchwellen::ABSAGE_KETTE as $i => $typ) {
                $absagen[$typ->value] = $werte[$i];
            }
        }
        // Explizite Trumpfzahl-Vorgabe heißt: nach Trumpfzahl entscheiden (alter Stand).
        $schwellen = $ansageTruempfe !== null && $ansageTruempfe !== ''
            ? new AnsageSchwellen($re, $contra, $absagen, $letztmoeglich, null, null)
            : new AnsageSchwellen($re, $contra, $absagen, $letztmoeglich);
        if ($ansageAugen !== null && $ansageAugen !== '') {
            $werte = array_map('floatval', array_map('trim', explode(',', $ansageAugen)));
            if (count($werte) !== 2) {
                throw new \InvalidArgumentException('Ansage-Augen erwarten zwei Werte: "re,contra".');
            }
            $schwellen = $schwellen->mitAugenSchwellen($werte[0], $werte[1]);
        }
        $schwellen = $schwellen->mitErspieltenAugen($mitErspieltenAugen)->mitBewerteRestblatt($bewerteRestblatt);

        return new EngineStrategie(
            $staerke,
            $this->anfaenger,
            $this->fortgeschritten,
            $this->profi,
            $this->ansageHeuristik,
            max(1, $rollouts),
            max(0.001, $zeitbudget),
            $ansagenAktiv,
            $regeln,
            $schwellen,
            $punkteBewertung,
            $nurZweiGegenZwei,
        );
    }
}
