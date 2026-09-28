<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Contract;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\RowChange;

/**
 * Receives every write to a #[Replicated] row while its transaction is still
 * open. Whatever it writes through $transaction commits or rolls back with the
 * data — that is what makes the capture lossless.
 *
 * The ORM ships no implementation; semitexa/ledger provides one. Throwing
 * aborts the write.
 */
interface ReplicationCaptureInterface
{
    public function capture(RowChange $change, DatabaseAdapterInterface $transaction): void;
}
