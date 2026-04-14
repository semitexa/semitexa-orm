<?php

declare(strict_types=1);

namespace Semitexa\Orm\Bootstrap;

final readonly class OrmBootstrapReport
{
    /**
     * @param list<class-string> $resourceModelClasses
     * @param list<class-string> $mapperClasses
     * @param list<class-string> $domainModelClasses
     * @param list<string> $crossConnectionWarnings
     */
    public function __construct(
        public array $resourceModelClasses,
        public array $mapperClasses,
        public array $domainModelClasses,
        public array $crossConnectionWarnings = [],
    ) {}
}
