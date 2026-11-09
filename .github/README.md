# Simple as fuck / Php performance log

Services for logging slow parts of application.

## Installation

```console
composer require simple-as-fuck/php-performance-log
```

## Support

If any PHP platform requirements in [composer.json](../composer.json) ends with security support,
consider package version as unsupported except last version.

[PHP supported versions](https://www.php.net/supported-versions.php).

### Measurements support

| Tools                                | Http server requests                  | Http client requests                                                 | DB transactions                       | SQL queries             | Console commands | Queue Jobs             |
|--------------------------------------|---------------------------------------|----------------------------------------------------------------------|---------------------------------------|-------------------------|------------------|------------------------|
| [Laravel](https://laravel.com/docs)  | With middleware use, default 1 second | Guzzle with handler stack build by Laravel, default 700 milliseconds | Default 300 milliseconds              | Default 50 milliseconds | Default off      | Recommended 40 seconds |
| [Guzzle](https://docs.guzzlephp.org) | Unsupported                           | With middleware use, default 700 milliseconds                        | Unsupported                           | Unsupported             | Unsupported      | Unsupported            |


### Laravel application

Global thresholds configuration are in `performance_log.php` config, publishable from package.

```console
php artisan vendor:publish --tag performance-log-config
```

For http server request time logging you must register [LaravelMiddleware](../src/Middleware/LaravelMiddleware.php)
as global on **first position**.

### Application with Guzzle

For http client request time logging you must register [GuzzleMiddleware](../src/Middleware/GuzzleMiddleware.php)
to your guzzle instance.

### Other applications

All package services or listeners do not have any external dependencies except PSR interfaces,
is possible use package for measurement of different types of applications.

You must register some [PerformanceLogConfig](../src/Service/PerformanceLogConfig.php) extends to your application
as unique global instance (singleton), because in the service state are hold temporary [thresholds overwrites](#thresholds-overwrite),
and you should overwrite methods `getConfig...`, where you can configure your global thresholds
or in methods load thresholds from application configuration files.

Thresholds values has behaviour:
not zero value will log longer runs then threshold value as warning,
`null` value turn off measurement,
zero value will log running time as debug.

You can look at [LaravelPerformanceLogConfig](../src/Service/LaravelPerformanceLogConfig.php) as example.

You must register [listeners](../src/Listener) to your application as singletons,
because most listeners has state and hold measurements data inside.
[PSR-14](https://www.php-fig.org/psr/psr-14/) listeners are callables,
you can register package listeners by anonymous function adapters.

You can look at [LaravelProvider](../src/Provider/LaravelProvider.php) as example.

For http server request time logging you must register [PsrMiddleware](../src/Middleware/PsrMiddleware.php).
Recommended usage is register middleware as global on **first position** and all of your server request will be measured.

## Thresholds overwrite

### Sql

If you know than some sql is slow, and you are fine with that you can overwrite global thresholds configuration
by setting a temporary threshold in [PerformanceLogConfig](../src/Service/PerformanceLogConfig.php).

```php
/** @var \SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig $performanceLogConfig */

$sqlThreshold = $performanceLogConfig->setSlowSqlQueryThreshold(null);
$transactionThreshold = $performanceLogConfig->setSlowDbTransactionThreshold(null);

// run some slow queries without annoying performance log

$sqlThreshold->restore();
$transactionThreshold->restore();
```

### Http client

If you know that some webservice is slow or some your microservice dependency should be extra fast,
you can overwrite global threshold configuration by setting a temporary threshold.

```php
/** @var \SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig $performanceLogConfig */

$requestThreshold = $performanceLogConfig->setSlowClientRequestThreshold(null);

// call http requests by your client

$requestThreshold->restore();
```

### Http server

If you know that some specific controller action is slow or should be extra fast,
you can overwrite global threshold configuration by setting a temporary threshold.
The temporary threshold can be set anywhere and multiple times in request run last set take effect
and live until a request ends.

```php
/** @var \SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig $performanceLogConfig */

$performanceLogConfig->setSlowServerRequestThreshold(null);

// run some extra slow logic without annoying performance log

// no need for threshold restoring, performance listener will handle it
```

### Console

If you want to overwrite global threshold configuration, you can do it by setting a temporary threshold.
The temporary threshold can be set anywhere and multiple times in command run last set take effect and live until a command ends.

```php
/** @var \SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig $performanceLogConfig */

$performanceLogConfig->setSlowCommandThreshold(60); // one minute

// run some measured logic

// no need for threshold restoring, performance listener will handle it
```

### Job

If you want to overwrite global threshold configuration, you can set a temporary threshold.
The temporary threshold can be set anywhere and multiple times in job run last set take effect and live until a job ends.

```php
/** @var \SimpleAsFuck\PerformanceLog\Service\PerformanceLogConfig $performanceLogConfig */

$performanceLogConfig->setSlowJobThreshold(10000); // 10 seconds

// run some measured logic

// no need for threshold restoring, performance listener will handle it
```

## Slow query explain

The package can log a query plan (`EXPLAIN`) of a slow select query, the explain is turned off by default.
The query plan is logged as info right after the slow query, so it is visible in the monitoring
next to the slow query (for example in Sentry breadcrumbs) and does not make a separate issue.

In Laravel application configure `database.slow_query_explain_threshold` in `performance_log.php` config,
the explain is supported for MySQL and MariaDB connections.
Only the tabular `EXPLAIN` is used, because `EXPLAIN FORMAT=JSON` can contain values from the query.
If the explain fails, only the exception class is logged, because the exception message can contain values from the query.

In other applications overwrite the method `getConfigSlowSqlQueryExplainThreshold` in your
[PerformanceLogConfig](../src/Service/PerformanceLogConfig.php) extends and pass a closure returning rows of the query plan
as the last argument of `DatabaseListener::onSqlQuery`, or null if your database does not support the explain.

```php
/** @var \SimpleAsFuck\PerformanceLog\Listener\DatabaseListener $databaseListener */

$databaseListener->onSqlQuery($sql, $time, $connectionName, static fn (): array => $connection->fetchAll('EXPLAIN '.$sql, $bindings));
```

## Usage with monitoring

Is recommended send performance warning logs into your monitoring system, so you know what is slow.

For simple monitoring is [sentry](https://docs.sentry.io/platforms/php/) integration.
Sentry integration can collect information about request or command with stacktrace,
this can make finding slow query much easier.
