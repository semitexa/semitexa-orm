<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;

trait HasUuid
{
    #[Column(type: MySqlType::Varchar, length: 36)]
    public string $uuid = '';

    /**
     * Called automatically by AbstractRepository::beforeSave().
     * Generates a UUID v4 if uuid is empty (first insert).
     * On subsequent saves the existing uuid is preserved.
     */
    public function ensureUuid(): void
    {
        if ($this->uuid !== '') {
            return;
        }

        // RFC 4122 UUID v4 — random bytes with version and variant bits set
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122

        $this->uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
