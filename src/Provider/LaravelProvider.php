<?php

declare(strict_types=1);

namespace SimpleAsFuck\PerformanceLog\Provider;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use SimpleAsFuck\PerformanceLog\Listener\ConsoleListener;
use SimpleAsFuck\PerformanceLog\Listener\DatabaseListener;
use SimpleAsFuck\PerformanceLog\Listener\HttpClientListener;
use SimpleAsFuck\PerformanceLog\Listener\HttpServerListener;
use SimpleAsFuck\PerformanceLog\Listener\QueueListener;
use SimpleAsFuck\PerformanceLog\Middleware\GuzzleMiddleware;
use SimpleAsFuck\PerformanceLog\Service\LaravelPerformanceLogConfig;
use SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig;
use SimpleAsFuck\PerformanceLog\Service\Stopwatch;

class LaravelProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PerformanceLogConfig::class, LaravelPerformanceLogConfig::class);
        $this->app->singleton(Stopwatch::class, fn () => new Stopwatch(
            $this->makePerformanceLogger(),
        ));

        $this->app->singleton(ConsoleListener::class, fn () => new ConsoleListener(
            $this->makePerformanceLogger(),
            $this->makePerformanceLogConfig(),
            $this->makeStopwatch(),
        ));
        $this->app->singleton(DatabaseListener::class, fn () => new DatabaseListener(
            $this->makePerformanceLogger(),
            $this->makePerformanceLogConfig(),
            $this->makeStopwatch(),
        ));
        $this->app->singleton(HttpClientListener::class, fn () => new HttpClientListener(
            $this->makePerformanceLogger(),
            $this->makePerformanceLogConfig(),
            $this->makeStopwatch(),
        ));
        $this->app->singleton(HttpServerListener::class, fn () => new HttpServerListener(
            $this->makePerformanceLogger(),
            $this->makePerformanceLogConfig(),
            $this->makeStopwatch(),
        ));
        $this->app->singleton(QueueListener::class, fn () => new QueueListener(
            $this->makePerformanceLogger(),
            $this->makePerformanceLogConfig(),
            $this->makeStopwatch(),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/laravel.php' => $this->app->configPath('performance_log.php'),
        ], 'performance-log-config');

        $this->app->make('events');
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var Factory $httpFactory */
        $httpFactory = $this->app->make(Factory::class);

        /** @var ConsoleListener $consoleListener */
        $consoleListener = $this->app->make(ConsoleListener::class);
        $dispatcher->listen(CommandStarting::class, static fn (CommandStarting $command) => $consoleListener->onCommandStart($command->command));
        $dispatcher->listen(CommandFinished::class, static fn (CommandFinished $command) => $consoleListener->onCommandFinish($command->command));

        /** @var DatabaseListener $databaseListener */
        $databaseListener = $this->app->make(DatabaseListener::class);
        /** @phpstan-ignore-next-line laravel developers are imbeciles in reality time for now return float|null */
        $dispatcher->listen(QueryExecuted::class, static fn (QueryExecuted $query) => $databaseListener->onSqlQuery($query->sql, $query->time ?? 0.0, $query->connectionName, self::makeExplainQuery($query)));
        $dispatcher->listen(TransactionBeginning::class, static fn (TransactionBeginning $transaction) => $databaseListener->onTransactionStart($transaction->connection->transactionLevel(), $transaction->connectionName));
        $dispatcher->listen(TransactionRolledBack::class, static fn (TransactionRolledBack $transaction) => $databaseListener->onTransactionFinnish($transaction->connection->transactionLevel(), $transaction->connectionName));
        $dispatcher->listen(TransactionCommitted::class, static fn (TransactionCommitted $transaction) => $databaseListener->onTransactionFinnish($transaction->connection->transactionLevel(), $transaction->connectionName));

        /** @var HttpClientListener $httpClientListener */
        $httpClientListener = $this->app->make(HttpClientListener::class);
        $httpFactory->globalMiddleware(new GuzzleMiddleware($httpClientListener));

        $queueListener = $this->app->make(QueueListener::class);
        /** @phpstan-ignore-next-line laravel developers are imbeciles in reality getJobId for now return int|string */
        $dispatcher->listen(JobProcessing::class, static fn (JobProcessing $job) => $queueListener->onJobStart($job->job->resolveName() . '-' . ((string) $job->job->getJobId())));
        /** @phpstan-ignore-next-line laravel developers are imbeciles in reality getJobId for now return int|string */
        $dispatcher->listen(JobFailed::class, static fn (JobFailed $job) => $queueListener->onJobFinish($job->job->resolveName(), $job->job->resolveName() . '-' . ((string) $job->job->getJobId())));
        /** @phpstan-ignore-next-line laravel developers are imbeciles in reality getJobId for now return int|string */
        $dispatcher->listen(JobProcessed::class, static fn (JobProcessed $job) => $queueListener->onJobFinish($job->job->resolveName(), $job->job->resolveName() . '-' . ((string) $job->job->getJobId())));
    }

    private function makePerformanceLogger(): LoggerInterface
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);
        /** @var LogManager $logManager */
        $logManager = $this->app->make(LogManager::class);

        $performanceLogChanel = $config->get('performance_log.log_channel');
        if (! is_string($performanceLogChanel)) {
            $performanceLogChanel = null;
        }
        return $logManager->channel($performanceLogChanel);
    }

    /**
     * @return (\Closure(): list<array<mixed>>)|null
     */
    private static function makeExplainQuery(QueryExecuted $query): ?\Closure
    {
        if (! \in_array($query->connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        // only the tabular explain, the JSON format can contain values from the query
        return static function () use ($query): array {
            $plan = [];
            foreach ($query->connection->select('EXPLAIN '.$query->sql, $query->bindings) as $row) {
                if (! \is_object($row)) {
                    throw new \UnexpectedValueException('Database query explain row is not an object');
                }
                $plan[] = \get_object_vars($row);
            }

            return $plan;
        };
    }

    private function makePerformanceLogConfig(): PerformanceLogConfig
    {
        /** @var PerformanceLogConfig */
        return $this->app->make(PerformanceLogConfig::class);
    }

    private function makeStopwatch(): Stopwatch
    {
        /** @var Stopwatch */
        return $this->app->make(Stopwatch::class);
    }
}
