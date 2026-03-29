<?php

declare(strict_types=1);

namespace Semitexa\Orm\Bootstrap;

final readonly class OrmBootstrapReport
{
    /**
     * @param list<class-string> $tableModelClasses
     * @param list<class-string> $mapperClasses
     * @param list<class-string> $domainModelClasses
     */
    public function __construct(
        public array $tableModelClasses,
        public array $mapperClasses,
        public array $domainModelClasses,
    ) {}
}
