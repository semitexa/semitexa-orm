<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;
use Semitexa\Orm\OrmManager;

#[AsDoctorCheck(name: 'orm.database', package: 'semitexa/orm')]
final class DatabaseDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        try {
            $version = new OrmManager()->getAdapter()->getServerVersion();
        } catch (\Throwable $e) {
            return DoctorResult::fail(
                'Database unreachable: ' . $e->getMessage(),
                'Check DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD and that the DB container is up '
                . '(env_file changes need a container recreate, not just restart).',
            );
        }

        return DoctorResult::pass("Database reachable, server version {$version}.");
    }
}
