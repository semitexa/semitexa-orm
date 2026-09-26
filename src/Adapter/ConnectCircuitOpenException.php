<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Raised instead of attempting a connect while {@see ConnectCircuitBreaker} is
 * open. Mirrors the failure that opened it — same message, code and errorInfo
 * — so callers and DriverErrorClassifier treat it exactly like the original
 * \PDOException; the original is available as getPrevious().
 */
final class ConnectCircuitOpenException extends \PDOException
{
    public static function from(\PDOException $failure): self
    {
        $e = new self($failure->getMessage(), 0, $failure);
        // PDOException codes are SQLSTATE strings; the constructor only takes int.
        $e->code = $failure->getCode();
        $e->errorInfo = $failure->errorInfo;

        return $e;
    }
}
