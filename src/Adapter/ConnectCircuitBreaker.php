<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Per-worker fail-fast guard around connection ESTABLISHMENT.
 *
 * Without it, every request that needs a new connection while the database is
 * down pays a full connect attempt — up to DB_CONNECT_TIMEOUT (5 s by default)
 * against an unresponsive host, per request, per worker — and queues up behind
 * each other holding pool slots. After a failed connect this breaker opens for
 * `cooldownSeconds`: connects in that window fail immediately with a
 * {@see ConnectCircuitOpenException} carrying the original failure's message,
 * code and errorInfo (so DriverErrorClassifier still maps it to
 * ConnectionLostException). When the window ends exactly one caller is let
 * through as a probe; other callers keep failing fast until it resolves. A
 * successful connect closes the breaker.
 *
 * It wraps only the pool's connection FACTORY, so already-open pooled
 * connections are never affected.
 *
 * Coroutine safety: all state transitions happen between yield points (the
 * only suspension is inside the factory call itself), so no two coroutines of
 * the same worker can both claim the probe. A probe coroutine that never
 * returns (killed mid-connect) releases its claim after `probeLeaseSeconds`.
 */
final class ConnectCircuitBreaker
{
    private ?\PDOException $lastFailure = null;

    private float $openUntil = 0.0;

    /** Start time of the in-flight half-open probe, or null when none. */
    private ?float $probeStartedAt = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param float $cooldownSeconds  fail-fast window after a connect failure; <= 0 disables the breaker
     * @param float $probeLeaseSeconds how long a half-open probe may run before another caller may probe
     * @param (\Closure(): float)|null $clock monotonic seconds; injectable for tests
     */
    public function __construct(
        private readonly float $cooldownSeconds,
        private readonly float $probeLeaseSeconds = 10.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * @param \Closure(): \PDO $factory
     * @return \Closure(): \PDO
     */
    public function wrap(\Closure $factory): \Closure
    {
        if ($this->cooldownSeconds <= 0.0) {
            return $factory;
        }

        return fn (): \PDO => $this->connect($factory);
    }

    /**
     * @param \Closure(): \PDO $factory
     */
    public function connect(\Closure $factory): \PDO
    {
        if ($this->cooldownSeconds <= 0.0) {
            return $factory();
        }

        $isProbe = false;
        $claim = null;
        if ($this->lastFailure !== null) {
            $now = ($this->clock)();
            $probeInFlight = $this->probeStartedAt !== null
                && ($now - $this->probeStartedAt) < $this->probeLeaseSeconds;

            if ($now < $this->openUntil || $probeInFlight) {
                throw ConnectCircuitOpenException::from($this->lastFailure);
            }

            $this->probeStartedAt = $now;
            $claim = $now;
            $isProbe = true;
        }

        try {
            $pdo = $factory();
        } catch (\PDOException $e) {
            $wasClosed = $this->lastFailure === null;
            $this->lastFailure = $e;
            $this->openUntil = ($this->clock)() + $this->cooldownSeconds;
            // Release the claim only while it is still ours: a probe that
            // outlived its lease may have been superseded by a newer one.
            if ($isProbe && $this->probeStartedAt === $claim) {
                $this->probeStartedAt = null;
            }
            if ($wasClosed) {
                StaticLoggerBridge::warning('orm', 'Database connect failed; failing fast for the cooldown window.', [
                    'cooldown_seconds' => $this->cooldownSeconds,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        } catch (\Throwable $e) {
            // Not a connect failure (e.g. a programming error in the factory):
            // do not trip, but do not leave a probe claim behind either.
            if ($isProbe && $this->probeStartedAt === $claim) {
                $this->probeStartedAt = null;
            }

            throw $e;
        }

        $this->lastFailure = null;
        $this->openUntil = 0.0;
        $this->probeStartedAt = null;

        return $pdo;
    }

    public function isOpen(): bool
    {
        return $this->lastFailure !== null;
    }
}
