<?php

declare(strict_types=1);

namespace SimpleAsFuck\PerformanceLog\Service;

use Illuminate\Contracts\Config\Repository;
use SimpleAsFuck\Validator\Factory\Validator;
use SimpleAsFuck\Validator\Rule\General\Rules;

class LaravelPerformanceLogConfig extends PerformanceLogConfig
{
    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /**
     * @return float|null threshold value in milliseconds
     */
    protected function getConfigSlowSqlQueryThreshold(): ?float
    {
        if ($this->config->has('performance_log.database.slow_query_threshold')) {
            return $this->getConfigValue('performance_log.database.slow_query_threshold')->float()->min(0)->nullable();
        }
        return parent::getConfigSlowSqlQueryThreshold();
    }

    /**
     * @return float|null threshold value in milliseconds
     */
    protected function getConfigSlowSqlQueryExplainThreshold(): ?float
    {
        if ($this->config->has('performance_log.database.slow_query_explain_threshold')) {
            return $this->getConfigValue('performance_log.database.slow_query_explain_threshold')->float()->min(0)->nullable();
        }

        return parent::getConfigSlowSqlQueryExplainThreshold();
    }

    /**
     * @return float|null threshold value in milliseconds
     */
    protected function getConfigSlowDbTransactionThreshold(): ?float
    {
        if ($this->config->has('performance_log.database.slow_transaction_threshold')) {
            return $this->getConfigValue('performance_log.database.slow_transaction_threshold')->float()->min(0)->nullable();
        }

        return parent::getConfigSlowDbTransactionThreshold();
    }

    /**
     * @return float|null threshold value in milliseconds
     */
    protected function getConfigSlowClientRequestThreshold(): ?float
    {
        if ($this->config->has('performance_log.http.slow_client_request_threshold')) {
            return $this->getConfigValue('performance_log.http.slow_client_request_threshold')->float()->min(0)->nullable();
        }

        return parent::getConfigSlowClientRequestThreshold();
    }

    /**
     * @return float|null threshold value in milliseconds
     */
    protected function getConfigSlowServerRequestThreshold(): ?float
    {
        if ($this->config->has('performance_log.http.slow_server_request_threshold')) {
            return $this->getConfigValue('performance_log.http.slow_server_request_threshold')->float()->min(0)->nullable();
        }

        return parent::getConfigSlowServerRequestThreshold();
    }

    /**
     * @return float|null threshold value in seconds
     */
    protected function getConfigSlowCommandThreshold(): ?float
    {
        if ($this->config->has('performance_log.console.slow_command_threshold')) {
            return $this->getConfigValue('performance_log.console.slow_command_threshold')->float()->min(0)->nullable();
        }

        return parent::getConfigSlowCommandThreshold();
    }

    /**
     * @return float|null threshold value in milliseconds
     */
    protected function getConfigSlowJobThreshold(): ?float
    {
        if ($this->config->has('performance_log.queue.slow_job_threshold')) {
            return $this->getConfigValue('performance_log.queue.slow_job_threshold')->float()->min(0)->nullable();
        }

        return parent::getConfigSlowJobThreshold();
    }

    /**
     * @param literal-string $key
     */
    private function getConfigValue(string $key): Rules
    {
        return Validator::make($this->config->get($key), 'Config key: '.$key);
    }
}
