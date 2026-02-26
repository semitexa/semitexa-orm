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

#[AsCommand(name: 'orm:diff', description: 'Show differences between code schema and database')]
class OrmDiffCommand extends BaseCommand
{
    public function __construct(
        private readonly OrmManager $orm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('orm:diff')
            ->setDescription('Show differences between code schema and database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $orm = $this->orm;

            // 1. Collect schema from code
            $collector = $orm->getSchemaCollector();
            $codeSchema = $collector->collect();

            $errors = $collector->getErrors();
            if ($errors !== []) {
                $io->error('Schema validation errors:');
                $io->listing($errors);
                return Command::FAILURE;
            }

            // 2. Compare with DB
            $comparator = $orm->getSchemaComparator();
            $diff = $comparator->compare($codeSchema);

            if ($diff->isEmpty()) {
                $io->success('No differences. Database matches code schema.');
    
                return Command::SUCCESS;
            }

            // Display diff
            foreach ($diff->getCreateTables() as $table) {
                $io->section("+ CREATE TABLE `{$table->name}`");
                foreach ($table->getColumns() as $col) {
                    $io->text("    + {$col->name} ({$col->type->value})");
                }
            }

            foreach ($diff->getAddColumns() as $tableName => $columns) {
                $io->section("~ ALTER TABLE `{$tableName}`");
                foreach ($columns as $col) {
                    $io->text("    <info>+ ADD COLUMN</info> {$col->name} ({$col->type->value})");
                }
            }

            foreach ($diff->getAlterColumns() as $tableName => $alterations) {
                if (!isset($diff->getAddColumns()[$tableName])) {
                    $io->section("~ ALTER TABLE `{$tableName}`");
                }
                foreach ($alterations as $alt) {
                    $col = $alt['column'];
                    $changes = implode(', ', $alt['changes']);
                    $io->text("    <comment>~ MODIFY COLUMN</comment> {$col->name}: {$changes}");
                }
            }

            foreach ($diff->getDropColumns() as $tableName => $columns) {
                if (!isset($diff->getAddColumns()[$tableName]) && !isset($diff->getAlterColumns()[$tableName])) {
                    $io->section("~ ALTER TABLE `{$tableName}`");
                }
                foreach ($columns as $colInfo) {
                    $io->text("    <fg=red>- DROP COLUMN</> {$colInfo['name']}");
                }
            }

            foreach ($diff->getAddIndexes() as $tableName => $indexes) {
                foreach ($indexes as $entry) {
                    $cols = implode(', ', $entry['index']->columns);
                    $io->text("    <info>+ ADD INDEX</info> {$entry['name']} ({$cols})");
                }
            }

            foreach ($diff->getDropIndexes() as $tableName => $indexNames) {
                foreach ($indexNames as $name) {
                    $io->text("    <fg=red>- DROP INDEX</> {$name}");
                }
            }

            foreach ($diff->getAddForeignKeys() as $fk) {
                $io->text("    <fg=green>+ ADD FK</> `{$fk->constraintName()}` ({$fk->table}.{$fk->column} → {$fk->referencedTable}.{$fk->referencedColumn}) ON DELETE {$fk->onDelete->value}");
            }

            foreach ($diff->getDropForeignKeys() as $entry) {
                $io->text("    <fg=red>- DROP FK</> `{$entry['constraintName']}` from `{$entry['table']}`");
            }

            foreach ($diff->getDropTables() as $dbTable) {
                $phase = $dbTable->tableComment === 'SEMITEXA_DEPRECATED' ? 'phase 2 — DROP' : 'phase 1 — deprecate';
                $io->section("- DROP TABLE `{$dbTable->name}` ({$phase})");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Diff failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
