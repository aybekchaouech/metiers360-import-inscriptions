<?php

namespace App\Command;

use App\Enum\ImportRunStatus;
use App\FormApi\FormApiPage;
use App\Import\InscriptionImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:inscriptions:import',
    description: 'Imports the registrations of the form API into the database (safe to run again, e.g. from cron).',
)]
class ImportInscriptionsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly InscriptionImporter $importer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Two overlapping cron runs would race on the same rows: the second one gives up.
        if (!$this->lock()) {
            $io->warning('An import is already running.');

            return Command::FAILURE;
        }

        try {
            $run = $this->importer->import(static function (FormApiPage $page) use ($io): void {
                $io->writeln(sprintf('Page %d/%d: %d submissions', $page->page, $page->totalPages, \count($page->submissions)));
            });
        } finally {
            $this->release();
        }

        $io->definitionList(
            ['Status' => $run->getStatus()->value],
            ['Pages read' => $run->getPagesRead()],
            ['Announced by the API' => $run->getTotalAnnounced() ?? '-'],
            ['Created' => $run->getCreated()],
            ['Updated' => $run->getUpdated()],
            ['Unchanged' => $run->getUnchanged()],
            ['Rejected' => $run->getRejected()],
            ['With anomalies' => $run->getWithAnomalies()],
        );

        if (ImportRunStatus::Success !== $run->getStatus()) {
            $io->error(sprintf('Import #%d failed: %s', $run->getId(), $run->getError()));

            // Non-zero exit code: cron and monitoring see the failure.
            return Command::FAILURE;
        }

        $io->success(sprintf('Import #%d completed.', $run->getId()));

        return Command::SUCCESS;
    }
}
