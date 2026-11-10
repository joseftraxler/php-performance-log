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
    // queries executed by the explain are never explained again, if some PDO wrapper reports them to the listener
    private bool $explaining = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PerformanceLogConfig $performanceLogConfig,
        private readonly Stopwatch $stopwatch,
    ) {
        $this->transactionMeasurement = new Measurement();
    }

    /**
     * @param float $time in milliseconds
     * @param array<mixed> $bindings
     */
    public function onSqlQuery(string $sql, float $time, ?string $connectionName, ?\PDO $pdo = null, array $bindings = []): void
    {
        $queryThreshold = $this->performanceLogConfig->getSlowSqlQueryThreshold();
        $explain = $pdo === null ? [] : $this->explainSqlQuery($sql, $time, $pdo, $bindings);

        if ($queryThreshold === 0.0) {
            $this->logger->debug('Database query time: '.$time.'ms sql: "'.$sql.'" connection: "'.$connectionName.'" pid: '.\getmypid(), $explain);
        } elseif ($queryThreshold !== null && $time >= $queryThreshold) {
            $this->logger->warning('Database query is too slow: '.$time.'ms sql: "'.$sql.'" threshold: '.$queryThreshold. 'ms connection: "'.$connectionName.'" pid: '.\getmypid(), $explain);
        } elseif ($explain !== []) {
            $this->logger->debug('Database query explain: '.$time.'ms sql: "'.$sql.'" connection: "'.$connectionName.'" pid: '.\getmypid(), $explain);
        }
    }

    /**
     * @param float $time in milliseconds
     * @param array<mixed> $bindings
     * @return array{explain?: list<array<mixed>>, explain_exception?: class-string<\Throwable>} log context
     */
    private function explainSqlQuery(string $sql, float $time, \PDO $pdo, array $bindings): array
    {
        $threshold = $this->performanceLogConfig->getSlowSqlQueryExplainThreshold();
        if ($this->explaining || $threshold === null || $time < $threshold) {
            return [];
        }
        if (\preg_match('/\A\s*select.*/i', $sql) !== 1) {
            return [];
        }
        // only the tabular explain of mysql, explain of another database can contain values from the query
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return [];
        }

        $this->explaining = true;
        try {
            $statement = $pdo->prepare('EXPLAIN '.$sql);
            if ($statement === false) {
                throw new \RuntimeException('Database query explain prepare failed');
            }
            // bindValue keeps the data types unlike execute parameters, but positional parameters are indexed from 1
            foreach ($bindings as $key => $value) {
                $statement->bindValue(\is_string($key) ? $key : $key + 1, $value, \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $statement->execute();
            /** @var list<array<mixed>> $explain */
            $explain = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $exception) {
            // the exception message can contain values from the query, so only the exception type is logged
            return ['explain_exception' => $exception::class];
        } finally {
            $this->explaining = false;
        }

        return ['explain' => $explain];
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
