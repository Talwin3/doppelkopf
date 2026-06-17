<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\SystemEinstellungService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:system:init',
    description: 'Initialisiert SystemEinstellungen mit Default-Werten (idempotent).',
)]
class SystemEinstellungenInitCommand extends Command
{
    public function __construct(
        private readonly SystemEinstellungService $einstellungService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->einstellungService->initialisiereDefaults();
        $output->writeln('<info>SystemEinstellungen initialisiert.</info>');

        return Command::SUCCESS;
    }
}
