<?php

declare(strict_types=1);

namespace Semitexa\Orm\Console\Command;

use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\OrmManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class OrmSyncCommand extends BaseCommand
{
    public function __construct(
        private readonly OrmManager $orm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('orm:sync')
            ->setDescription('Synchronize ORM schema with the database')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show SQL plan without executing')
            ->addOption('allow-destructive', null, InputOption::VALUE_NONE, 'Allow destructive operations (DROP, type narrowing)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Save SQL plan to file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $allowDestructive = (bool) $input->getOption('allow-destructive');
        $outputFile = $input->getOption('output');

        try {
            $orm = $this->orm;

            // 1. Collect schema from code
            $io->section('Collecting schema from code...');
            $collector = $orm->getSchemaCollector();
            $codeSchema = $collector->collect();

            $errors = $collector->getErrors();
            if ($errors !== []) {
                $io->error('Schema validation errors:');
                $io->listing($errors);
                return Command::FAILURE;
            }

            $warnings = $collector->getWarnings();
            if ($warnings !== []) {
                $io->warning('Schema warnings:');
                $io->listing($warnings);
            }

            $io->text(sprintf('Found %d table(s) in code.', count($codeSchema)));

            // 2. Compare with DB
            $io->section('Comparing with database...');
            $comparator = $orm->getSchemaComparator();
            $diff = $comparator->compare($codeSchema);

            if ($diff->isEmpty()) {
                $io->success('Database is up to date. No changes needed.');

                return Command::SUCCESS;
            }

            // 3. Build execution plan
            $syncEngine = $orm->getSyncEngine();
            $plan = $syncEngine->buildPlan($diff);

            $io->text($plan->getSummary());
            $io->newLine();

            // Show plan details
            $safeOps = $plan->getSafeOperations();
            $destructiveOps = $plan->getDestructiveOperations();

            if ($safeOps !== []) {
                $io->section('Safe operations:');
                foreach ($safeOps as $op) {
                    $io->text("  <info>[{$op->type->value}]</info> {$op->description}");
                    if ($output->isVerbose()) {
                        $io->text("    SQL: {$op->sql}");
                    }
                }
            }

            if ($destructiveOps !== []) {
                $io->section('Destructive operations:');
                foreach ($destructiveOps as $op) {
                    $io->text("  <fg=red>[{$op->type->value}]</> {$op->description}");
                    if ($output->isVerbose()) {
                        $io->text("    SQL: {$op->sql}");
                    }
                }

                if (!$allowDestructive) {
                    $io->warning('Destructive operations will be skipped. Use --allow-destructive to include them.');
                }
            }

            // Save to file if requested
            if ($outputFile !== null) {
                $statements = $plan->toSqlStatements($allowDestructive);
                $content = implode(";\n", $statements) . ";\n";
                file_put_contents($outputFile, $content);
                $io->text("SQL plan saved to: {$outputFile}");
            }

            // Execute if not dry-run
            if ($dryRun) {
                $io->note('Dry run mode — no changes applied.');

                return Command::SUCCESS;
            }

            $executed = $syncEngine->execute($plan, $allowDestructive);
            $io->success(sprintf('Executed %d operation(s).', count($executed)));

            $orm->shutdown();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Sync failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
