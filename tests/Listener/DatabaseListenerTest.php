<?php

declare(strict_types=1);

namespace SimpleAsFuck\PerformanceLog\Tests\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleAsFuck\PerformanceLog\Listener\DatabaseListener;
use SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig;
use SimpleAsFuck\PerformanceLog\Service\Stopwatch;
use SimpleAsFuck\PerformanceLog\Tests\TestLogger;

#[CoversClass(DatabaseListener::class)]
final class DatabaseListenerTest extends TestCase
{
    private const string SELECT = 'select * from `users` where `email` = ?';
    private const string SECRET = 'secret@example.com';
    private const array PLAN = [
        ['id' => 1, 'select_type' => 'SIMPLE', 'table' => 'users', 'type' => 'ALL', 'rows' => 1000],
    ];

    public function testSlowSelectPlanIsLogged(): void
    {
        $logger = new TestLogger();
        $listener = self::makeListener($logger, 2000);

        $listener->onSqlQuery(self::SELECT, 2500.0, 'mysql', static fn (): array => self::PLAN);

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertStringStartsWith('Database query explain: 2500ms sql: "'.self::SELECT.'" threshold: 2000ms connection: "mysql"', $logger->records[0]['message']);
        self::assertSame(['explain' => self::PLAN], $logger->records[0]['context']);
    }

    public function testExplainIsLoggedAfterSlowQueryWarning(): void
    {
        $logger = new TestLogger();
        $listener = self::makeListener($logger, 2000, 50);

        $listener->onSqlQuery(self::SELECT, 2500.0, 'mysql', static fn (): array => self::PLAN);

        self::assertSame(['warning', 'info'], \array_column($logger->records, 'level'));
    }

    /**
     * @param (\Closure(): list<array<mixed>>)|null $explainQuery
     */
    #[DataProvider('provideNotExplainedQuery')]
    public function testQueryIsNotExplained(string $sql, float $time, ?float $threshold, ?\Closure $explainQuery): void
    {
        $logger = new TestLogger();
        $listener = self::makeListener($logger, $threshold);

        $listener->onSqlQuery($sql, $time, 'mysql', $explainQuery);

        self::assertSame([], $logger->records);
    }

    /**
     * @return iterable<string, array{string, float, float|null, (\Closure(): list<array<mixed>>)|null}>
     */
    public static function provideNotExplainedQuery(): iterable
    {
        $explainQuery = static fn (): array => self::fail('Query should not be explained.');

        yield 'fast select' => [self::SELECT, 1999.0, 2000.0, $explainQuery];
        yield 'slow update' => ['update `users` set `name` = null where `email` = ?', 2500.0, 2000.0, $explainQuery];
        yield 'slow insert with select' => ['insert into `users_copy` select * from `users`', 2500.0, 2000.0, $explainQuery];
        yield 'explain turned off' => [self::SELECT, 60000.0, null, $explainQuery];
        yield 'explain not supported' => [self::SELECT, 2500.0, 2000.0, null];
    }

    public function testFailedExplainDoesNotThrowAndDoesNotLogExceptionMessage(): void
    {
        $logger = new TestLogger();
        $listener = self::makeListener($logger, 2000);

        $listener->onSqlQuery(self::SELECT, 2500.0, 'mysql', static fn (): array => throw new \RuntimeException('Query failed for '.self::SECRET));

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertStringStartsWith('Database query explain failed: '.\RuntimeException::class, $logger->records[0]['message']);
        self::assertStringNotContainsString(self::SECRET, \serialize($logger->records));
    }

    public function testQueryExecutedByExplainIsNotExplained(): void
    {
        $logger = new TestLogger();
        $explainCalls = 0;
        $explainQuery = static function () use (&$explainCalls, &$explainQuery, $logger): array {
            // nested query handled by another listener instance, the limit only stops a broken protection
            if (++$explainCalls <= 3) {
                self::makeListener($logger, 2000)->onSqlQuery(self::SELECT, 2500.0, 'mysql', $explainQuery);
            }

            return self::PLAN;
        };

        self::makeListener($logger, 2000)->onSqlQuery(self::SELECT, 2500.0, 'mysql', $explainQuery);

        self::assertSame(1, $explainCalls);
        self::assertCount(1, $logger->records);
    }

    public function testQueryIsExplainedAfterFailedExplain(): void
    {
        $logger = new TestLogger();
        $listener = self::makeListener($logger, 2000);

        $listener->onSqlQuery(self::SELECT, 2500.0, 'mysql', static fn (): array => throw new \RuntimeException());
        $listener->onSqlQuery(self::SELECT, 2500.0, 'mysql', static fn (): array => self::PLAN);

        self::assertCount(2, $logger->records);
        self::assertSame(['explain' => self::PLAN], $logger->records[1]['context']);
    }

    private static function makeListener(TestLogger $logger, ?float $explainThreshold, ?float $queryThreshold = null): DatabaseListener
    {
        $config = new class ($explainThreshold, $queryThreshold) extends PerformanceLogConfig {
            public function __construct(
                private readonly ?float $explainThreshold,
                private readonly ?float $queryThreshold,
            ) {
            }

            protected function getConfigSlowSqlQueryThreshold(): ?float
            {
                return $this->queryThreshold;
            }

            protected function getConfigSlowSqlQueryExplainThreshold(): ?float
            {
                return $this->explainThreshold;
            }
        };

        return new DatabaseListener($logger, $config, new Stopwatch($logger));
    }
}
