<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Environment;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Orm\Application\Service\Sync\SchemaReset;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops every table of a connection and syncs the schema from the code, so a
 * development database starts from nothing in one command
 * (bench-no-fresh-schema-command). It deletes all data, so it refuses to run
 * outside APP_ENV dev, local or test.
 */
#[AsCommand(name: 'orm:fresh', description: 'Drop every table and sync the schema from code (development only; deletes all data)')]
final class OrmFreshCommand extends BaseCommand
{
    /** Where a database's data is worth nothing: anything else is refused. */
    public const ENVIRONMENTS = ['dev', 'local', 'test'];

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    public function __construct()
    {
        parent::__construct('orm:fresh');
    }

    protected function configure(): void
    {
        $this
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name to reset', 'default')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Run orm:seed after the sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = strtolower((string) Environment::getEnvValue('APP_ENV', ''));
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            $io->error(sprintf('orm:fresh deletes every table and its data; it runs only with APP_ENV %s (this is "%s").', implode(', ', self::ENVIRONMENTS), $env));

            return Command::FAILURE;
        }
        $requested = $input->getOption('connection');
        $connection = is_string($requested) ? trim($requested) : '';
        if ($connection === '') {
            // orm:sync refuses it too, but only after the tables are gone.
            $io->error('The --connection option must not be empty.');

            return Command::FAILURE;
        }
        $seed = (bool) $input->getOption('seed');
        if ($seed && $connection !== 'default') {
            // orm:seed seeds the default connection only.
            $io->error('--seed works with the default connection only; orm:seed has no --connection.');

            return Command::FAILURE;
        }
        $orm = $this->connections->manager($connection);
        // The schema the sync will build, checked before anything is dropped:
        // orm:sync refuses a schema with errors, and it would refuse it here
        // with the database already empty.
        $collector = $orm->getSchemaCollector();
        $collector->collect();
        if ($collector->getErrors() !== []) {
            $io->error('Schema validation errors; nothing was dropped:');
            $io->listing($collector->getErrors());
            $orm->shutdown();

            return Command::FAILURE;
        }
        $reset = new SchemaReset($orm->getAdapter(), $orm->getDriver(), $orm->getDatabaseName());
        $dropped = $reset->dropAll();
        $io->text(sprintf('Dropped %d table(s) from %s (connection: %s).', count($dropped), $orm->getDatabaseName(), $connection));
        $orm->shutdown();

        $application = $this->getApplication() ?? throw new \LogicException('orm:fresh runs inside the console application');
        $synced = $application->find('orm:sync')->run(new ArrayInput(['--connection' => $connection]), $output);
        if ($synced !== Command::SUCCESS || !$seed) {
            return $synced;
        }

        return $application->find('orm:seed')->run(new ArrayInput([]), $output);
    }
}
