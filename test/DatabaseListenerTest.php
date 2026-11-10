<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SimpleAsFuck\PerformanceLog\Listener\DatabaseListener;
use SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig;
use SimpleAsFuck\PerformanceLog\Service\Stopwatch;

#[CoversClass(DatabaseListener::class)]
final class DatabaseListenerTest extends TestCase
{
    private const string SELECT = 'select * from `users` where `email` = ?';
    private const string SECRET = 'secret@example.com';
    private const array EXPLAIN = [
        ['id' => 1, 'select_type' => 'SIMPLE', 'table' => 'users', 'type' => 'ALL', 'rows' => 1000],
    ];

    public function testSlowSelectExplainIsLogged(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('bindValue')->with(1, self::SECRET, \PDO::PARAM_STR);
        $statement->expects(self::once())->method('execute');
        $statement->method('fetchAll')->willReturn(self::EXPLAIN);
        $pdo = $this->makePdo();
        $pdo->expects(self::once())->method('prepare')->with('EXPLAIN '.self::SELECT)->willReturn($statement);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            self::stringStartsWith('Database query explain: 2500ms sql: "'.self::SELECT.'" connection: "mysql"'),
            ['explain' => self::EXPLAIN],
        );

        $listener = $this->makeListener($logger, null, 2000);
        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $pdo, [self::SECRET]);
    }

    public function testSlowSelectExplainIsLoggedWithSlowQueryWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringStartsWith('Database query is too slow: 2500ms'), ['explain' => self::EXPLAIN]);
        $logger->expects(self::never())->method('debug');

        $listener = $this->makeListener($logger, 50, 2000);
        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $this->makeExplainingPdo(), [self::SECRET]);
    }

    public function testSlowSelectExplainIsLoggedWithQueryTime(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(self::stringStartsWith('Database query time: 2500ms'), ['explain' => self::EXPLAIN]);

        $listener = $this->makeListener($logger, 0, 2000);
        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $this->makeExplainingPdo(), [self::SECRET]);
    }

    #[DataProvider('dataQueryIsNotExplained')]
    public function testQueryIsNotExplained(string $sql, float $time, ?float $explainThreshold, bool $withPdo, string $driver): void
    {
        $pdo = $this->makePdo($driver);
        $pdo->expects(self::never())->method('prepare');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        $listener = $this->makeListener($logger, null, $explainThreshold);
        $listener->onSqlQuery($sql, $time, 'mysql', $withPdo ? $pdo : null, [self::SECRET]);
    }

    /**
     * @return non-empty-array<string, array{string, float, float|null, bool, string}>
     */
    public static function dataQueryIsNotExplained(): array
    {
        return [
            'fast select' => [self::SELECT, 1999, 2000, true, 'mysql'],
            'slow update' => ['update `users` set `name` = null where `email` = ?', 2500, 2000, true, 'mysql'],
            'slow insert with select' => ['insert into `users_copy` select * from `users` where `email` = ?', 2500, 2000, true, 'mysql'],
            'explain turned off' => [self::SELECT, 60000, null, true, 'mysql'],
            'without pdo' => [self::SELECT, 2500, 2000, false, 'mysql'],
            'not mysql database' => [self::SELECT, 2500, 2000, true, 'pgsql'],
        ];
    }

    public function testFailedExplainLogsOnlyExceptionClass(): void
    {
        $pdo = $this->makePdo();
        $pdo->method('prepare')->willThrowException(new \PDOException('Explain failed for '.self::SECRET));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            self::logicalNot(self::stringContains(self::SECRET)),
            ['explain_exception' => \PDOException::class],
        );

        $listener = $this->makeListener($logger, null, 2000);
        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $pdo, [self::SECRET]);
    }

    public function testQueryExecutedByExplainIsNotExplained(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');
        $listener = $this->makeListener($logger, null, 2000);

        $statement = self::createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn(self::EXPLAIN);
        $pdo = $this->makePdo();
        $calls = 0;
        // a query executed by the explain is reported back to the listener, the limit stops a broken protection
        $pdo->expects(self::once())->method('prepare')->willReturnCallback(static function () use ($listener, $pdo, $statement, &$calls): \PDOStatement {
            if (++$calls <= 3) {
                $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $pdo, [self::SECRET]);
            }

            return $statement;
        });

        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $pdo, [self::SECRET]);
    }

    public function testQueryIsExplainedAfterFailedExplain(): void
    {
        $statement = self::createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn(self::EXPLAIN);
        $pdo = $this->makePdo();
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnOnConsecutiveCalls(self::throwException(new \PDOException()), $statement);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('debug');

        $listener = $this->makeListener($logger, null, 2000);
        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $pdo, [self::SECRET]);
        $listener->onSqlQuery(self::SELECT, 2500, 'mysql', $pdo, [self::SECRET]);
    }

    private function makeListener(LoggerInterface $logger, ?float $queryThreshold, ?float $explainThreshold): DatabaseListener
    {
        $config = $this->getMockBuilder(PerformanceLogConfig::class)
            ->onlyMethods(['getConfigSlowSqlQueryThreshold', 'getConfigSlowSqlQueryExplainThreshold'])
            ->getMock();
        $config->method('getConfigSlowSqlQueryThreshold')->willReturn($queryThreshold);
        $config->method('getConfigSlowSqlQueryExplainThreshold')->willReturn($explainThreshold);

        return new DatabaseListener($logger, $config, self::createStub(Stopwatch::class));
    }

    private function makeExplainingPdo(): \PDO
    {
        $statement = self::createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn(self::EXPLAIN);
        $pdo = $this->makePdo();
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function makePdo(string $driver = 'mysql'): \PDO&MockObject
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn($driver);

        return $pdo;
    }
}
