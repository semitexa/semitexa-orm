<?php

declare(strict_types=1);

namespace Semitexa\Orm\Contract;

interface DomainMappable
{
    public function toDomain(): object;

    public static function fromDomain(object $entity): static;
}
