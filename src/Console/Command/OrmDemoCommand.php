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

/**
 * Runs real ORM demo: sync, seed, then CRUD and filter examples using OrmDemo module.
 * Requires src/modules/OrmDemo/Application/Orm (ORM models) and Application/Repository to be present.
 */
#[AsCommand(name: 'orm:demo', description: 'Run real ORM demo (sync, seed, CRUD, filters on main and related models)')]
class OrmDemoCommand extends BaseCommand
{
    private const USER_REPOSITORY_CLASS = \Semitexa\Modules\OrmDemo\Application\Repository\UserRepository::class;

    protected function configure(): void
    {
        $this->setName('orm:demo')
            ->setDescription('Run real ORM demo (sync, seed, CRUD, filters on main and related models)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!class_exists(self::USER_REPOSITORY_CLASS)) {
            $io->warning('Demo not available: OrmDemo module not found.');
            $io->text('Add src/modules/OrmDemo/Application/Orm and Application/Repository (Role, User, Order, OrderItem) to run the demo.');
            return Command::SUCCESS;
        }

        try {
            return (int) OrmManager::run(function (OrmManager $orm) use ($io, $input, $output): int {
                return $this->runDemo($orm, $io, $output->isVerbose());
            });
        } catch (\Throwable $e) {
            $io->error('Demo failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function runDemo(OrmManager $orm, SymfonyStyle $io, bool $verbose): int
    {
        $adapter = $orm->getAdapter();

        $io->title('Semitexa ORM Demo');

        // 1. Sync schema
        $io->section('1. Schema sync');
        $collector = $orm->getSchemaCollector();
        $codeSchema = $collector->collect();
        $errors = $collector->getErrors();
        if ($errors !== []) {
            $io->error('Schema errors:');
            $io->listing($errors);
            return Command::FAILURE;
        }
        $comparator = $orm->getSchemaComparator();
        $diff = $comparator->compare($codeSchema);
        if (!$diff->isEmpty()) {
            $syncEngine = $orm->getSyncEngine();
            $plan = $syncEngine->buildPlan($diff);
            $syncEngine->execute($plan, true);
            $io->text('Schema synchronized.');
        } else {
            $io->text('Schema already up to date.');
        }

        // 2. Seed
        $io->section('2. Seed (defaults)');
        $seedResults = $orm->getSeedRunner()->run();
        $io->text('Seed completed: ' . json_encode($seedResults, JSON_THROW_ON_ERROR));

        $hydrator = new \Semitexa\Orm\Hydration\Hydrator();
        $relationLoader = new \Semitexa\Orm\Hydration\RelationLoader($adapter, $hydrator);
        $streamingHydrator = new \Semitexa\Orm\Hydration\StreamingHydrator($hydrator, $relationLoader);

        $roleRepo = new \Semitexa\Modules\OrmDemo\Application\Repository\RoleRepository($adapter);
        $userRepo = new \Semitexa\Modules\OrmDemo\Application\Repository\UserRepository($adapter, $streamingHydrator);
        $orderRepo = new \Semitexa\Modules\OrmDemo\Application\Repository\OrderRepository($adapter, $streamingHydrator);
        $orderItemRepo = new \Semitexa\Modules\OrmDemo\Application\Repository\OrderItemRepository($adapter);

        // 3. Read: findById, findAll, findBy
        $io->section('3. Read (findById, findAll, findBy)');
        $role1 = $roleRepo->findById(1);
        $io->text('Role by id 1: ' . ($role1 ? "{$role1->slug} - {$role1->name}" : 'null'));
        $users = $userRepo->findAll(5);
        $io->text('Users (limit 5): ' . count($users) . ' row(s)');
        $paidOrders = $orderRepo->findBy(['status' => 'paid']);
        $io->text('Orders with status=paid: ' . count($paidOrders) . ' row(s)');

        // 4. Filter by main model (FilterableResourceInterface)
        $io->section('4. Filter by main model (filterByX)');
        $filterResource = new \Semitexa\Modules\OrmDemo\Application\Orm\UserResource();
        $filterResource->filterByEmail('demo@semitexa.dev');
        $found = $userRepo->find($filterResource);
        $io->text('Users with email=demo@semitexa.dev: ' . count($found) . ' row(s)');
        if ($found !== []) {
            $io->text('  First: id=' . $found[0]->id . ', name=' . $found[0]->name);
        }

        // 5. Filter by related model (filterByUserEmail on Order)
        $io->section('5. Filter by related model (filterByUserEmail on Order)');
        $orderFilter = new \Semitexa\Modules\OrmDemo\Application\Orm\OrderResource();
        $orderFilter->filterByUserEmail('demo@semitexa.dev');
        $ordersByUserEmail = $orderRepo->find($orderFilter);
        $io->text('Orders where user.email=demo@semitexa.dev: ' . count($ordersByUserEmail) . ' row(s)');

        // 6. Query builder: whereRelation (via repository method)
        $io->section('6. Query builder whereRelation');
        $ordersWithStatus = $orderRepo->findByUserEmailAndStatus('demo@semitexa.dev', 'paid');
        $io->text('Orders (user.email=demo@semitexa.dev AND status=paid): ' . count($ordersWithStatus) . ' row(s)');

        // 7. Create (save) new entity
        $io->section('7. Create (save)');
        $newUser = new \Semitexa\Modules\OrmDemo\Application\Orm\UserResource();
        $newUser->email = 'newuser@demo.dev';
        $newUser->name = 'New Demo User';
        $userRepo->save($newUser);
        $io->text('Created user id=' . $newUser->id . ', email=' . $newUser->email);

        // 8. Create order with items (cascade save)
        $newOrder = new \Semitexa\Modules\OrmDemo\Application\Orm\OrderResource();
        $newOrder->user_id = $newUser->id;
        $newOrder->status = 'pending';
        $newOrder->total = 149.99;
        $item1 = new \Semitexa\Modules\OrmDemo\Application\Orm\OrderItemResource();
        $item1->product_name = 'Product A';
        $item1->quantity = 2;
        $item1->price = 49.99;
        $item2 = new \Semitexa\Modules\OrmDemo\Application\Orm\OrderItemResource();
        $item2->product_name = 'Product B';
        $item2->quantity = 1;
        $item2->price = 50.01;
        $newOrder->items = [$item1, $item2];
        $orderRepo->save($newOrder);
        $io->text('Created order id=' . $newOrder->id . ' with ' . count($newOrder->items) . ' items');

        // 9. Eager load relations (with)
        $io->section('9. Eager load relations (with)');
        $oneOrder = $orderRepo->findByIdWithRelations($newOrder->id);
        if ($oneOrder !== null) {
            $io->text('Order id=' . $oneOrder->id . ', user_id=' . $oneOrder->user_id);
            $io->text('  Loaded user: ' . (isset($oneOrder->user) && $oneOrder->user !== null ? $oneOrder->user->email : 'no'));
            $io->text('  Loaded items: ' . (is_array($oneOrder->items ?? null) ? count($oneOrder->items) : 0) . ' item(s)');
        }

        // 10. Update (save again)
        $io->section('10. Update (save)');
        $newUser->name = 'Updated Demo User';
        $userRepo->save($newUser);
        $refetched = $userRepo->findById($newUser->id);
        $io->text('Updated user name to: ' . ($refetched ? $refetched->name : '?'));

        // 11. Pagination
        $io->section('11. Pagination');
        $page1 = $orderRepo->findPaginated(1, 2);
        $io->text('Orders page 1 (perPage=2): ' . count($page1->items) . ' items, total=' . $page1->total);

        // 12. Delete (cascade)
        $io->section('12. Delete (cascade)');
        $orderRepo->delete($newOrder);
        $userRepo->delete($newUser);
        $io->text('Deleted demo order and user.');

        $io->success('ORM demo completed successfully.');
        return Command::SUCCESS;
    }
}
