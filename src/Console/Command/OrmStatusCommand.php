<?php

declare(strict_types=1);

namespace Semitexa\Orm\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'orm:status', description: 'Show ORM status: database info, server capabilities, schema summary')]
class OrmStatusCommand extends BaseCommand
{
    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('orm:status')
            ->setDescription('Show ORM status: database info, server capabilities, schema summary')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name to inspect', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connectionOption = $input->getOption('connection');
        $connection = is_string($connectionOption) && $connectionOption !== '' ? $connectionOption : 'default';

        try {
            $orm = $this->connections->manager($connection);
            $adapter = $orm->getAdapter();
            $isSqlite = str_contains(strtolower($adapter::class), 'sqlite');

            // Server info
            $io->section('Database Server');
            $serverInfo = [
                ['Server Version' => $adapter->getServerVersion()],
                ['Database' => $orm->getDatabaseName()],
            ];
            if (!$isSqlite) {
                $serverInfo[] = ['Pool Size' => (string) $orm->getPool()->getSize()];
            }
            $io->definitionList(...$serverInfo);

            // Capabilities
            $io->section('Server Capabilities');
            $rows = [];
            foreach (ServerCapability::cases() as $capability) {
                $supported = $adapter->supports($capability);
                $rows[] = [
                    $capability->value,
                    $capability->name,
                    $supported ? '<info>Yes</info>' : '<fg=red>No</>',
                ];
            }
            $io->table(['Capability', 'Name', 'Supported'], $rows);

            // Code schema summary
            $io->section('Code Schema');
            $collector = $orm->getSchemaCollector();
            $schema = $collector->collect();

            $errors = $collector->getErrors();
            $warnings = $collector->getWarnings();

            $totalColumns = 0;
            $totalIndexes = 0;
            foreach ($schema as $table) {
                $totalColumns += count($table->getColumns());
                $totalIndexes += count($table->getIndexes());
            }

            $io->definitionList(
                ['Tables' => (string) count($schema)],
                ['Total Columns' => (string) $totalColumns],
                ['Total Indexes' => (string) $totalIndexes],
                ['Validation Errors' => (string) count($errors)],
                ['Validation Warnings' => (string) count($warnings)],
            );

            if ($errors !== []) {
                $io->error('Validation errors:');
                $io->listing($errors);
            }

            if ($warnings !== []) {
                $io->warning('Warnings:');
                $io->listing($warnings);
            }

            // Sync status
            $io->section('Sync Status');
            $comparator = $orm->getSchemaComparator();
            $diff = $comparator->compare($schema);

            if ($diff->isEmpty()) {
                $io->success('Database is in sync with code.');
            } else {
                $syncEngine = $orm->getSyncEngine();
                $plan = $syncEngine->buildPlan($diff);
                $io->warning('Database is out of sync.');
                $io->text($plan->getSummary());
            }


            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Status check failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
