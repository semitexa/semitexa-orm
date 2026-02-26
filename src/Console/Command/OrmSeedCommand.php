<?php

declare(strict_types=1);

namespace Semitexa\Orm\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\OrmManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'orm:seed', description: 'Run defaults() upsert for all Resource classes with seed data')]
class OrmSeedCommand extends BaseCommand
{
    public function __construct(
        private readonly OrmManager $orm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('orm:seed')
            ->setDescription('Run defaults() upsert for all Resource classes with seed data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $orm = $this->orm;

            $io->section('Running seed (defaults) upsert...');

            $results = $orm->getSeedRunner()->run();

            if ($results === []) {
                $io->note('No Resource classes with defaults() found.');
    
                return Command::SUCCESS;
            }

            $totalInserted = 0;
            $totalUpdated = 0;
            $totalUnchanged = 0;

            $rows = [];
            foreach ($results as $tableName => $stats) {
                $rows[] = [$tableName, $stats['inserted'], $stats['updated'], $stats['unchanged']];
                $totalInserted += $stats['inserted'];
                $totalUpdated += $stats['updated'];
                $totalUnchanged += $stats['unchanged'];
            }

            $io->table(
                ['Table', 'Inserted', 'Updated', 'Unchanged'],
                $rows,
            );

            $io->success(sprintf(
                'Seed complete: %d inserted, %d updated, %d unchanged across %d table(s).',
                $totalInserted,
                $totalUpdated,
                $totalUnchanged,
                count($results),
            ));


            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Seed failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
