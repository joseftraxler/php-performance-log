<?php

declare(strict_types=1);

namespace SimpleAsFuck\PerformanceLog\Listener;

use Psr\Log\LoggerInterface;
use SimpleAsFuck\PerformanceLog\Data\Measurement;
use SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig;
use SimpleAsFuck\PerformanceLog\Service\Stopwatch;

class DatabaseListener
{
    private readonly Measurement $transactionMeasurement;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PerformanceLogConfig $performanceLogConfig,
        private readonly Stopwatch $stopwatch,
    ) {
        $this->transactionMeasurement = new Measurement();
    }

    /**
     * @param float $time in milliseconds
     * @param (\Closure(): list<array<mixed>>)|null $explainQuery returns query plan rows, null if not supported
     */
    public function onSqlQuery(string $sql, float $time, ?string $connectionName, ?\Closure $explainQuery = null): void
    {
        $this->logSqlQuery($sql, $time, $connectionName);

        if ($explainQuery !== null) {
            $this->explainSqlQuery($sql, $time, $connectionName, $explainQuery);
        }
    }

    /**
     * @param float $time in milliseconds
     */
    private function logSqlQuery(string $sql, float $time, ?string $connectionName): void
    {
        $queryThreshold = $this->performanceLogConfig->getSlowSqlQueryThreshold();

        if ($queryThreshold === null) {
            return;
        }
        if ($queryThreshold === 0.0) {
            $this->logger->debug('Database query time: '.$time.'ms sql: "'.$sql.'" connection: "'.$connectionName.'" pid: '.\getmypid());
            return;
        }
        if ($time >= $queryThreshold) {
            $this->logger->warning('Database query is too slow: '.$time.'ms sql: "'.$sql.'" threshold: '.$queryThreshold. 'ms connection: "'.$connectionName.'" pid: '.\getmypid());
        }
    }

    /**
     * The query plan is logged as info, so it is shown next to the slow query without a separate issue.
     *
     * @param float $time in milliseconds
     * @param \Closure(): list<array<mixed>> $explainQuery
     */
    private function explainSqlQuery(string $sql, float $time, ?string $connectionName, \Closure $explainQuery): void
    {
        // shared by all listener instances, so queries executed by the explain are never explained again
        static $explaining = false;
        if ($explaining === true) {
            return;
        }

        $threshold = $this->performanceLogConfig->getSlowSqlQueryExplainThreshold();
        if ($threshold === null || $time < $threshold) {
            return;
        }
        if (\preg_match('/^\s*select\b/i', $sql) !== 1) {
            return;
        }

        $explaining = true;
        try {
            $plan = $explainQuery();
        } catch (\Throwable $exception) {
            // the exception message can contain values from the query, so only the exception type is logged
            $this->logger->info('Database query explain failed: '.$exception::class.' sql: "'.$sql.'" connection: "'.$connectionName.'" pid: '.\getmypid());
            return;
        } finally {
            $explaining = false;
        }

        $this->logger->info('Database query explain: '.$time.'ms sql: "'.$sql.'" threshold: '.$threshold.'ms connection: "'.$connectionName.'" pid: '.\getmypid(), [
            'explain' => $plan,
        ]);
    }

    public function onTransactionStart(int $transactionLevel, ?string $connectionName): void
    {
        if ($transactionLevel !== 1) {
            return;
        }

        $transactionThreshold = $this->performanceLogConfig->getSlowDbTransactionThreshold();
        if ($transactionThreshold === null) {
            return;
        }
        if ($transactionThreshold === 0.0) {
            $this->logger->debug('Database transaction begin connection: "'.$connectionName.'" pid: '.\getmypid());
        }

        $this->stopwatch->start($this->transactionMeasurement, $connectionName);
    }

    public function onTransactionFinnish(int $transactionLevel, ?string $connectionName): void
    {
        if ($transactionLevel !== 0) {
            return;
        }

        $threshold = $this->performanceLogConfig->getSlowDbTransactionThreshold();
        if ($threshold === null) {
            return;
        }

        if ($this->transactionMeasurement->get($connectionName) === null) {
            $this->logger->error('Database transaction measurement not running database connection: "'.$connectionName.'" pid: '.\getmypid().', check if begin transaction is called before commit/rollback!');
            return;
        }

        $time = $this->stopwatch->finishMilliseconds($this->transactionMeasurement, $connectionName);
        if ($threshold === 0.0) {
            $this->logger->debug('Database transaction time: '.$time.'ms connection: "'.$connectionName.'" pid: '.\getmypid());
            return;
        }
        if ($time >= $threshold) {
            $this->logger->warning('Database transaction is too slow: '.$time.'ms threshold: '.$threshold. 'ms connection: "'.$connectionName.'" pid: '.\getmypid());
        }
    }
}
