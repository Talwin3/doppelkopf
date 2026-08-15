<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Doppelkopf\Service\AnsageHeuristik;
use App\Domain\Doppelkopf\Service\BewertungsStatistik;
use App\Domain\Doppelkopf\Service\BotHeuristik;
use App\Domain\Doppelkopf\Service\FortgeschritteneHeuristik;
use App\Domain\Doppelkopf\Service\ProfiHeuristik;
use App\Domain\Doppelkopf\Simulation\EngineStrategie;
use App\Domain\Doppelkopf\Simulation\EvidenzDiagnose;
use App\Domain\Doppelkopf\Simulation\SimulierteStrategie;
use App\Domain\Doppelkopf\Simulation\TurnierErgebnis;
use App\Domain\Doppelkopf\Simulation\TurnierLauf;
use App\Domain\Doppelkopf\ValueObject\AnsageRollout;
use App\Domain\Doppelkopf\ValueObject\AnsageSchwellen;
use App\Domain\Doppelkopf\ValueObject\FortgeschritteneRegeln;
use App\Enum\BotStaerke;
use App\Enum\EndspielPolitik;
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
        private readonly EvidenzDiagnose $evidenzDiagnose,
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
            ->addOption('profi-1gegen3-a', null, InputOption::VALUE_NONE, 'A lässt Welten mit stiller Hochzeit (1 gegen 3) bei der Determinisierung zu (Stand vor dem 2026-08-07)')
            ->addOption('profi-1gegen3-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('ansage-rollout-a', null, InputOption::VALUE_NONE, 'A entscheidet die Ansage per Monte-Carlo-Rollout statt über die Blattbewertung (nur PROFI)')
            ->addOption('ansage-rollout-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('rollout-schwellen-a', null, InputOption::VALUE_NONE, 'A entscheidet die Rollout-Ansage nach den Essener Wahrscheinlichkeitsschwellen statt nach dem Erwartungswert')
            ->addOption('rollout-schwellen-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('rollout-absagen-a', null, InputOption::VALUE_NONE, 'A entscheidet auch keine 90/60/30/schwarz per Rollout')
            ->addOption('rollout-absagen-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('rollout-absagen-schwellen-a', null, InputOption::VALUE_NONE, 'A entscheidet NUR die Absagen nach den Essener Schwellen, Re/Contra weiter nach Erwartungswert')
            ->addOption('rollout-absagen-schwellen-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('rollout-nur-absagen-a', null, InputOption::VALUE_NONE, 'A entscheidet die Erstansage weiter über die Blattbewertung und nutzt die Rollouts nur für die Absagen')
            ->addOption('rollout-nur-absagen-b', null, InputOption::VALUE_NONE, 'dito für B')
            ->addOption('rollout-welten-a', null, InputOption::VALUE_REQUIRED, 'Welten je Ansage-Entscheidung für A', '60')
            ->addOption('rollout-welten-b', null, InputOption::VALUE_REQUIRED, 'dito für B', '60')
            ->addOption('endspiel-a', null, InputOption::VALUE_REQUIRED, 'A rechnet das Endspiel exakt aus (Alpha-Beta), sobald keine Hand mehr als N Karten hält; 0 = aus', '0')
            ->addOption('endspiel-b', null, InputOption::VALUE_REQUIRED, 'dito für B', '0')
            ->addOption('endspiel-politik-a', null, InputOption::VALUE_REQUIRED, 'Gegenspiel, das A im Endspiel-Loeser unterstellt: minimax|gegner-greedy|nur-ich', 'minimax')
            ->addOption('endspiel-politik-b', null, InputOption::VALUE_REQUIRED, 'dito für B', 'minimax')
            ->addOption('endspiel-diagnose', null, InputOption::VALUE_NONE, 'Endspiel zusaetzlich greedy bewerten und zaehlen, wie oft die exakte Suche die Kartenwahl aendert (etwa doppelte Laufzeit; bei anderer Politik als minimax wird zusaetzlich Minimax gerechnet)')
            ->addOption('ohne-team-nebenbedingung-a', null, InputOption::VALUE_NONE, 'A ignoriert in der Determinisierung die bekannten Teams wieder (Stand vor dem 15.08.2026). Normalerweise gilt: wer Re angesagt hat, bekommt eine Kreuz-Dame, wer Contra angesagt hat, keine')
            ->addOption('ohne-team-nebenbedingung-b', null, InputOption::VALUE_NONE, 'dito fuer B')
            ->addOption('schmier-evidenz-a', null, InputOption::VALUE_REQUIRED, 'Gewicht der weichen Schmier-Evidenz fuer A (0 = aus). Wer als Letzter hohe Augen abwirft, haelt den Stichfuehrenden fuer seinen Partner - gemessen zu 93 Prozent richtig gegen regelbasierte Gegner. Widersprechende Welten werden mit dieser Wahrscheinlichkeit verworfen, nicht ausgeschlossen', '0')
            ->addOption('schmier-evidenz-b', null, InputOption::VALUE_REQUIRED, 'dito fuer B', '0')
            ->addOption('evidenz-diagnose', null, InputOption::VALUE_NONE, 'Weiche Team-Signale (Schmieren/Stechen) gegen die wahren Parteien halten und je Signal Haeufigkeit, Trefferquote und Zeitpunkt ausweisen. Aendert nichts am Spiel, kostet praktisch keine Zeit (Hebel 3, zweiter Schritt)')
            ->addOption('bewertungs-diagnose', null, InputOption::VALUE_NONE, 'Beide Bewertungsmassstaebe je Welt mitschreiben: Gewichts-Sweep (v = w*s + e) und Trennschaerfe zwischen bester und zweitbester Karte. Aendert die Kartenwahl nicht, kostet eine zusaetzliche Abrechnung je Welt und Kandidat')
            ->addOption('nach-team', null, InputOption::VALUE_NONE, 'A spielt die ganze RE-, B die ganze KONTRA-Partei (gespiegelt) statt fester Sitze – nötig für Ansage-Vergleiche, siehe TurnierLauf')
            ->addOption('dump', null, InputOption::VALUE_REQUIRED, 'Die Differenz je Blatt als JSON in diese Datei schreiben – erlaubt den gepaarten Vergleich zweier A-Varianten (siehe --vergleiche)')
            ->addOption('vergleiche', null, InputOption::VALUE_REQUIRED, 'Zwei mit --dump erzeugte Dateien gepaart vergleichen ("x.json,y.json") und beenden')
            ->addOption('regeln-liste', null, InputOption::VALUE_NONE, 'Nur die bekannten FORTGESCHRITTEN-Regeln auflisten und beenden')
            ->addOption('ohne-neuner', null, InputOption::VALUE_NONE, 'Mit 40-Karten-Blatt spielen')
            ->addOption('zweite-dulle', null, InputOption::VALUE_NONE, 'Zweite Dulle sticht');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (($paar = $input->getOption('vergleiche')) !== null) {
            return $this->vergleiche($io, (string) $paar);
        }

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
                !$input->getOption('profi-1gegen3-a'),
                $this->baueRollout(
                    (bool) $input->getOption('ansage-rollout-a'),
                    !$input->getOption('rollout-schwellen-a'),
                    (bool) $input->getOption('rollout-absagen-a'),
                    (int) $input->getOption('rollout-welten-a'),
                    (bool) $input->getOption('rollout-absagen-schwellen-a'),
                    (bool) $input->getOption('rollout-nur-absagen-a'),
                ),
                (int) $input->getOption('endspiel-a'),
                (bool) $input->getOption('endspiel-diagnose'),
                EndspielPolitik::vonWert((string) $input->getOption('endspiel-politik-a')),
                (bool) $input->getOption('bewertungs-diagnose'),
                !$input->getOption('ohne-team-nebenbedingung-a'),
                (float) $input->getOption('schmier-evidenz-a'),
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
                !$input->getOption('profi-1gegen3-b'),
                $this->baueRollout(
                    (bool) $input->getOption('ansage-rollout-b'),
                    !$input->getOption('rollout-schwellen-b'),
                    (bool) $input->getOption('rollout-absagen-b'),
                    (int) $input->getOption('rollout-welten-b'),
                    (bool) $input->getOption('rollout-absagen-schwellen-b'),
                    (bool) $input->getOption('rollout-nur-absagen-b'),
                ),
                (int) $input->getOption('endspiel-b'),
                (bool) $input->getOption('endspiel-diagnose'),
                EndspielPolitik::vonWert((string) $input->getOption('endspiel-politik-b')),
                (bool) $input->getOption('bewertungs-diagnose'),
                !$input->getOption('ohne-team-nebenbedingung-b'),
                (float) $input->getOption('schmier-evidenz-b'),
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
            ['Verteilung' => $input->getOption('nach-team')
                ? 'nach Partei (A = RE, gespiegelt) – A und B sitzen nie zusammen'
                : 'nach Sitzplatz (A = 1/3, gespiegelt)'],
            ['Seed' => (string) $seed],
            ['Regeln' => sprintf(
                'ohne Neuner: %s, zweite Dulle sticht: %s',
                $regelEinstellungen['ohne_neuner'] ? 'ja' : 'nein',
                $regelEinstellungen['zweite_dulle_sticht'] ? 'ja' : 'nein',
            )],
        );

        $this->profi->statistik->zuruecksetzen();
        $this->profi->endspielStatistik->zuruecksetzen();
        $this->evidenzDiagnose->statistik->zuruecksetzen();
        if ($input->getOption('evidenz-diagnose')) {
            $this->evidenzDiagnose->aktivieren();
        }

        $fortschritt = $io->createProgressBar($anzahl);
        $fortschritt->start();

        $ergebnis = $this->turnierLauf->spiele(
            $a,
            $b,
            $anzahl,
            $seed,
            $regelEinstellungen,
            static fn(int $blatt) => $fortschritt->setProgress($blatt),
            (bool) $input->getOption('nach-team'),
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
                ['davon Absagen A / B', sprintf('%d / %d', $ergebnis->absagenA, $ergebnis->absagenB)],
                ['davon gehalten A / B', sprintf(
                    '%d / %d  (%s / %s)',
                    $ergebnis->gehalteneAnsagenA,
                    $ergebnis->gehalteneAnsagenB,
                    $this->quote($ergebnis->trefferquoteA()),
                    $this->quote($ergebnis->trefferquoteB()),
                )],
                ['Dauer', sprintf('%.1f s (%.3f s/Partie)', $ergebnis->dauerSek, $ergebnis->dauerSek / max(1, $ergebnis->anzahlBlaetter() * 2))],
            ],
        );

        $this->zeigeWeltStatistik($io);
        $this->zeigeEndspielStatistik($io);
        $this->zeigeBewertungsStatistik($io);
        $this->zeigeEvidenzStatistik($io);

        if (($ziel = $input->getOption('dump')) !== null) {
            file_put_contents((string) $ziel, json_encode([
                'name_a'      => $ergebnis->nameA,
                'name_b'      => $ergebnis->nameB,
                'seed'        => $seed,
                'ansagen_a'   => $ergebnis->ansagenA,
                'absagen_a'   => $ergebnis->absagenA,
                'gehalten_a'  => $ergebnis->gehalteneAnsagenA,
                'differenzen' => $ergebnis->differenzen,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $io->note(sprintf('Differenzen je Blatt nach %s geschrieben.', $ziel));
        }

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
        ?AnsageRollout $rollout,
        int $endspielAbRestkarten,
        bool $endspielDiagnose,
        EndspielPolitik $endspielPolitik,
        bool $bewertungsDiagnose,
        bool $teamNebenbedingung,
        float $schmierEvidenz,
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
        if ($rollout !== null) {
            $schwellen = $schwellen->mitRollout($rollout);
        }

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
            max(0, $endspielAbRestkarten),
            $endspielDiagnose,
            $endspielPolitik,
            $bewertungsDiagnose,
            $teamNebenbedingung,
            max(0.0, min(1.0, $schmierEvidenz)),
        );
    }

    private function quote(?float $anteil): string
    {
        return $anteil === null ? '–' : sprintf('%.1f %%', $anteil * 100);
    }

    /**
     * Reichweite der exakten Endspielsuche (nur mit `--endspiel-diagnose` gefüllt).
     *
     * Die letzte Zeile ist die entscheidende: sie begrenzt den erreichbaren Effekt nach oben.
     * Ändert die Suche die Kartenwahl so gut wie nie, kann sie die Spielstärke nicht bewegen –
     * dann erübrigt sich der lange Stärkelauf.
     */
    private function zeigeEndspielStatistik(SymfonyStyle $io): void
    {
        $s = $this->profi->endspielStatistik;
        if ($s->zuege === 0) {
            return;
        }

        $io->table(['Endspiel-Reichweite', 'Wert'], [
            ['bewertete Züge', number_format($s->zuege, 0, ',', '.')],
            ['davon mit exakter Suche', sprintf(
                '%s  (%.2f %%)',
                number_format($s->zuegeMitEndspiel, 0, ',', '.'),
                $s->quoteMitEndspiel(),
            )],
            ['davon andere Karte als Greedy', sprintf(
                '%s  (%.2f %% dieser Züge)',
                number_format($s->zuegeWahlGeaendert, 0, ',', '.'),
                $s->trefferquoteImEndspiel(),
            )],
            ['Wahländerungen je Zug gesamt', sprintf('%.2f %%', $s->quoteWahlGeaendert())],
            // Bleibt 0, solange A selbst mit Minimax rechnet – dann ist die Zeile bedeutungslos.
            ['davon andere Karte als Minimax', sprintf(
                '%s  (%.2f %% dieser Züge)',
                number_format($s->zuegeAbweichendVonMinimax, 0, ',', '.'),
                $s->quoteAbweichendVonMinimax(),
            )],
        ]);
    }

    /**
     * Diagnose der Bewertungsformel `v = w · s + e` (siehe {@see BewertungsStatistik}).
     *
     * Zwei Blöcke: oben der Gewichts-Sweep – ab welchem Gewicht die Punkte überhaupt eine
     * andere Karte wählen als die Augen (Effekt-Obergrenze); unten die Trennschärfe – ob der
     * Abstand zwischen bester und zweitbester Karte bei dieser Weltenzahl statistisch
     * überhaupt trägt. Die untere Hälfte prüft die Varianz-Hypothese direkt.
     */
    private function zeigeBewertungsStatistik(SymfonyStyle $io): void
    {
        $s = $this->profi->bewertungsStatistik;
        if ($s->zuege === 0) {
            return;
        }

        $zeilen = [
            ['bewertete Züge', number_format($s->zuege, 0, ',', '.')],
            ['Ø Kandidaten je Zug', sprintf('%.2f', $s->kandidatenSchnitt())],
            ['Ø Welten je Zug', sprintf('%.1f', $s->weltenSchnitt())],
        ];
        foreach (BewertungsStatistik::GEWICHTE as $w) {
            if ($w === 0) {
                continue;
            }
            $zeilen[] = [sprintf('andere Karte als Augen bei w=%d', $w), sprintf(
                '%s  (%.2f %%)',
                number_format($s->wahlGeaendert[$w], 0, ',', '.'),
                $s->quoteWahlGeaendert($w),
            )];
        }
        $zeilen[] = ['Rangfolge 1↔2 nicht abgesichert – Augen', sprintf(
            '%s  (%.2f %%)',
            number_format($s->unentschiedenAugen, 0, ',', '.'),
            $s->quoteUnentschiedenAugen(),
        )];
        $zeilen[] = ['Rangfolge 1↔2 nicht abgesichert – Punkte', sprintf(
            '%s  (%.2f %%)',
            number_format($s->unentschiedenPunkte, 0, ',', '.'),
            $s->quoteUnentschiedenPunkte(),
        )];
        $zeilen[] = ['⤷ davon einstimmig (t = ∞) – Augen / Punkte', sprintf(
            '%s / %s',
            number_format($s->einstimmigAugen, 0, ',', '.'),
            number_format($s->einstimmigPunkte, 0, ',', '.'),
        )];
        $zeilen[] = ['Median t – Augen / Punkte', sprintf('%.2f / %.2f', $s->tMedianAugen(), $s->tMedianPunkte())];
        $zeilen[] = ['Ø t ohne einstimmige Züge – Augen / Punkte', sprintf(
            '%.2f / %.2f  (schwankt stark, siehe Median)',
            $s->tSchnittAugen(),
            $s->tSchnittPunkte(),
        )];
        $zeilen[] = ['Median Abstand Platz 1↔2 (Augen)', sprintf('%.2f', $s->abstandMedianAugen())];
        $zeilen[] = ['davon praktisch gleichwertig (< 5 Augen)', sprintf(
            '%s  (%.2f %%)',
            number_format($s->praktischGleichwertig, 0, ',', '.'),
            $s->quotePraktischGleichwertig(),
        )];
        $zeilen[] = ['⭐ spürbarer Abstand UND Münzwurf', sprintf(
            '%s  (%.2f %%)',
            number_format($s->teuerUndUnentschieden, 0, ',', '.'),
            $s->quoteTeuerUndUnentschieden(),
        )];
        $zeilen[] = ['hochgerechnet r=300: unentschieden – Augen', sprintf(
            '%s  (%.2f %%)',
            number_format($s->unentschiedenBei300Augen, 0, ',', '.'),
            $s->quoteUnentschiedenBei300Augen(),
        )];
        $zeilen[] = ['hochgerechnet r=300: unentschieden – Punkte', sprintf(
            '%s  (%.2f %%)',
            number_format($s->unentschiedenBei300Punkte, 0, ',', '.'),
            $s->quoteUnentschiedenBei300Punkte(),
        )];

        $io->table(['Bewertungs-Diagnose', 'Wert'], $zeilen);
    }

    /**
     * Diagnose der weichen Team-Signale (Hebel 3, zweiter Schritt).
     *
     * Beantwortet vor dem teuren Messlauf zwei Fragen getrennt: **taugt** das Signal (Trefferquote
     * deutlich über 50 %?) und **trägt** es überhaupt (wie viele Aussagen betreffen ein Paar, das
     * nicht ohnehin schon feststeht, und wie früh fallen sie?). Ein Signal muss beide Hürden
     * nehmen – zuverlässig und selten ist genauso nutzlos wie häufig und zufällig.
     */
    private function zeigeEvidenzStatistik(SymfonyStyle $io): void
    {
        $s = $this->evidenzDiagnose->statistik;
        if ($s->istLeer()) {
            return;
        }

        $zeilen = [];
        foreach ($s->auswertung() as $z) {
            $zeilen[] = [
                $z['signal'],
                number_format($z['neu'], 0, ',', '.'),
                sprintf('%.3f', $z['proSicht']),
                sprintf('%.1f %%', $z['basis']),
                sprintf('%.2f %%', $z['quoteNeu']),
                sprintf('%+.2f', $z['vorsprung']),
                sprintf('%+.1f', $z['z']),
            ];
        }

        // Die rohe Trefferquote steht bewusst nicht in der Tabelle: sie verleitet dazu, 70 %
        // für stark zu halten, obwohl zwei Drittel davon schon die Basisrate sind.
        $io->table(
            ['Signal', 'neue Aussagen', 'je Sicht', 'Basisrate', 'gemessen', '⭐ Vorsprung', 'z'],
            $zeilen,
        );

        $io->table(['Evidenz-Diagnose', 'Wert'], [
            ['Sichten (4 je Stich)', number_format($s->sichten, 0, ',', '.')],
            ['davon mit neuer Aussage', sprintf(
                '%s  (%.2f %%)',
                number_format($s->sichtenMitNeuerAussage, 0, ',', '.'),
                $s->quoteSichtenMitNeuerAussage(),
            )],
            ['fremde Sitze betrachtet', number_format($s->fremdeSitze, 0, ',', '.')],
            ['davon hart bekannt (Schritt 1)', sprintf(
                '%s  (%.2f %%)',
                number_format($s->fremdeSitzeHartBekannt, 0, ',', '.'),
                $s->quoteHartBekannt(),
            )],
        ]);

        $verteilung = [];
        foreach ($s->proStich() as $z) {
            $verteilung[] = [sprintf('Stich %d', $z['stich']), number_format($z['anzahl'], 0, ',', '.'), sprintf('%.2f %%', $z['anteil'])];
        }
        if ($verteilung !== []) {
            $io->table(['Wann entsteht die neue Evidenz?', 'Aussagen', 'Anteil'], $verteilung);
        }
    }

    /**
     * Diagnose der Determinisierung – nur relevant, wenn überhaupt ein PROFI mitgespielt hat.
     *
     * Zeigt, wie oft sich `ProfiHeuristik::determinisiere()` festfährt und wie oft deshalb ein
     * ganzer Rollout ausfällt. Grundlage für die Entscheidung, ob der Umbau auf Sievers'
     * CAP-Algorithmus (Zuweisung ohne Verwerfen) lohnt.
     */
    private function zeigeWeltStatistik(SymfonyStyle $io): void
    {
        $s = $this->profi->statistik;
        if ($s->weltAnlaeufe === 0) {
            return;
        }

        $io->table(['Determinisierung', 'Wert'], [
            ['Ziehungen gesamt', number_format($s->ziehungen, 0, ',', '.')],
            ['davon ohne Ergebnis', sprintf(
                '%s  (%.3f %%)',
                number_format($s->ziehungenOhneErgebnis, 0, ',', '.'),
                $s->quoteZiehungenOhneErgebnis(),
            )],
            ['als 1-gegen-3 verworfen', sprintf(
                '%s  (%.2f %% der gezogenen Welten)',
                number_format($s->verworfenEinGegenDrei, 0, ',', '.'),
                $s->quoteEinGegenDrei(),
            )],
            ['Rollouts mit Weltsuche', number_format($s->weltAnlaeufe, 0, ',', '.')],
            ['davon ausgefallen', sprintf(
                '%s  (%.3f %%)',
                number_format($s->weltAusfaelle, 0, ',', '.'),
                $s->quoteWeltAusfaelle(),
            )],
            ['Welten mit bekanntem Team', number_format($s->weltenMitTeamFakten, 0, ',', '.')],
            ['⭐ davon dem Team widersprechend', sprintf(
                '%s  (%.2f %%)',
                number_format($s->weltenMitTeamWiderspruch, 0, ',', '.'),
                $s->quoteTeamWiderspruch(),
            )],
            ['wegen Schmier-Evidenz verworfen', number_format($s->verworfenGegenEvidenz, 0, ',', '.')],
        ]);
    }

    /**
     * Vergleicht zwei mit `--dump` erzeugte Läufe **gepaart**.
     *
     * Bis hierher galten Ansage-Varianten als nicht gepaart messbar: A gegen B verzerrt die
     * Ansagen (A und B sitzen oft im selben Team und nehmen einander die Team-Ansage weg),
     * also muss jede Variante einzeln gegen ANFAENGER laufen – und die Differenz zweier
     * solcher Läufe hatte kein Konfidenzintervall.
     *
     * Sie lässt sich aber sehr wohl paaren: Laufen X und Y auf demselben Seed, ist die
     * Differenz je Blatt (X − Anfänger) − (Y − Anfänger) = X − Y auf demselben Blatt. Das
     * kürzt das Kartenglück heraus, das in beiden Läufen dasselbe ist, und ist um ein
     * Vielfaches schärfer als der Vergleich zweier Mittelwerte.
     *
     * ⚠️ Was es **nicht** kürzt: Beide Läufe verbrauchen unterschiedlich viele Zufallszahlen,
     * sobald sie verschieden oft Rollouts anwerfen. Ab der ersten abweichenden Entscheidung
     * spielen sie dasselbe Blatt mit anderen Determinisierungen – die Streuung der
     * PIMC-Kartenwahl bleibt also drin. Gepaart wird das Blatt, nicht der Spielverlauf.
     */
    private function vergleiche(SymfonyStyle $io, string $paar): int
    {
        $dateien = array_map('trim', explode(',', $paar));
        if (count($dateien) !== 2) {
            $io->error('--vergleiche erwartet zwei Dateien: "x.json,y.json".');

            return Command::FAILURE;
        }

        $laeufe = [];
        foreach ($dateien as $datei) {
            if (!is_file($datei)) {
                $io->error(sprintf('Datei nicht gefunden: %s', $datei));

                return Command::FAILURE;
            }
            $laeufe[] = json_decode((string) file_get_contents($datei), true);
        }

        [$x, $y] = $laeufe;
        if ($x['seed'] !== $y['seed']) {
            $io->error(sprintf('Verschiedene Seeds (%d vs %d) – die Blätter sind nicht dieselben.', $x['seed'], $y['seed']));

            return Command::FAILURE;
        }
        if (count($x['differenzen']) !== count($y['differenzen'])) {
            $io->error('Verschieden viele Blätter – nicht paarbar.');

            return Command::FAILURE;
        }

        $paarweise = [];
        foreach ($x['differenzen'] as $i => $wert) {
            $paarweise[] = $wert - $y['differenzen'][$i];
        }

        $ergebnis = new TurnierErgebnis($x['name_a'], $y['name_a'], $paarweise, 0, 0, 0, 0.0);
        $mittel   = $ergebnis->mittelwert();
        $halb     = $ergebnis->konfidenzHalbbreite();

        $io->title('Gepaarter Vergleich');
        $io->table(['Kennzahl', 'Wert'], [
            ['X', $x['name_a']],
            ['Y', $y['name_a']],
            ['Seed', (string) $x['seed']],
            ['Blätter', (string) $ergebnis->anzahlBlaetter()],
            ['Ansagen X / Y', sprintf('%d / %d', $x['ansagen_a'], $y['ansagen_a'])],
            ['davon Absagen X / Y', sprintf('%d / %d', $x['absagen_a'] ?? 0, $y['absagen_a'] ?? 0)],
            ['Ø Punkte/Spieler/Partie (X − Y)', sprintf('%+.4f', $mittel)],
            ['95-%-Konfidenzintervall', sprintf('%+.4f … %+.4f', $mittel - $halb, $mittel + $halb)],
            ['Standardabweichung', sprintf('%.4f', $ergebnis->standardabweichung())],
        ]);

        $ergebnis->signifikant()
            ? $io->success(sprintf('Signifikanter Unterschied (%+.4f).', $mittel))
            : $io->warning('Kein signifikanter Unterschied auf dem 95-%-Niveau.');

        return Command::SUCCESS;
    }

    /** null = Ansage weiter über die Blattbewertung entscheiden. */
    private function baueRollout(
        bool $an,
        bool $evVergleich,
        bool $absagen,
        int $welten,
        bool $absagenNachSchwellen = false,
        bool $nurAbsagen = false,
    ): ?AnsageRollout {
        // „Nur Absagen" ergibt ohne eingeschaltete Absagen keinen Sinn – dann bliebe nichts.
        $absagen = $absagen || $nurAbsagen;

        return $an || $nurAbsagen
            ? new AnsageRollout($evVergleich, $absagen, AnsageRollout::SICHERHEIT_ESSEN, max(1, $welten), $absagenNachSchwellen, $nurAbsagen)
            : null;
    }
}
