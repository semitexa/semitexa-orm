<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;

/**
 * Backing table for the 'database' queue transport — broker-less async
 * dispatch for installs without NATS. Rows are deleted on ack; failed
 * messages (attempt_count >= max_attempts) stay for inspection. Payloads are
 * opaque strings and already carry tenant context via TenantAwareJobSerializer,
 * so the table itself is infrastructure, not tenant data.
 *
 * The transport reads/writes this table with raw adapter SQL (claim-lease
 * pattern); this resource exists so orm:sync owns the schema.
 */
#[FromTable(name: 'queue_messages')]
#[Index(columns: ['queue_name', 'status', 'queued_at'], name: 'idx_queue_messages_claim')]
#[Index(columns: ['lease_owner', 'status'], name: 'idx_queue_messages_lease')]
final readonly class QueueMessageResource
{
    use HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id = '',

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $queue_name = '',

        #[Column(type: MySqlType::LongText)]
        public string $payload = '',

        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $status = 'queued',

        #[Column(type: MySqlType::Int)]
        public int $attempt_count = 0,

        #[Column(type: MySqlType::Int)]
        public int $max_attempts = 5,

        #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $lease_owner = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $lease_expires_at = null,

        #[Column(type: MySqlType::Varchar, length: 512, nullable: true)]
        public ?string $last_error = null,

        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $queued_at = null,
    ) {
    }
}
