<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

class AuditLogger
{
    public function __construct(
        private readonly string $historyDir,
    ) {}

    /**
     * @param DdlOperation[] $operations
     */
    public function log(array $operations): void
    {
        if ($operations === []) {
            return;
        }

        if (!is_dir($this->historyDir)) {
            mkdir($this->historyDir, 0755, true);
        }

        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        $timestamp = $now !== false ? $now->format('Y-m-d_H-i-s.v') : date('Y-m-d_H-i-s') . '.' . substr((string) microtime(), 2, 3);
        $filename = $this->historyDir . '/' . $timestamp . '_sync.json';

        $entries = [];
        foreach ($operations as $op) {
            $entries[] = [
                'type' => $op->type->value,
                'table' => $op->tableName,
                'destructive' => $op->isDestructive,
                'description' => $op->description,
                'sql' => $op->sql,
            ];
        }

        $data = [
            'timestamp' => date('c'),
            'operations_count' => count($operations),
            'operations' => $entries,
        ];

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Also write a .sql file for DevOps convenience
        $sqlFilename  = $this->historyDir . '/' . $timestamp . '_sync.sql';
        $sqlLines = [
            '-- Semitexa ORM Sync — ' . date('c'),
            '-- Operations: ' . count($operations),
            '',
        ];

        foreach ($operations as $op) {
            $sqlLines[] = '-- ' . $op->description;
            $sqlLines[] = $op->sql . ';';
            $sqlLines[] = '';
        }

        file_put_contents($sqlFilename, implode("\n", $sqlLines));
    }
}
