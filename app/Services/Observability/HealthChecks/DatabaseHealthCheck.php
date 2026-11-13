<?php

namespace App\Services\Observability\HealthChecks;

use App\Data\HealthCheckResultData;
use App\Services\Observability\Contracts\HealthCheck;
use Illuminate\Database\DatabaseManager;

final readonly class DatabaseHealthCheck implements HealthCheck
{
    public function __construct(private DatabaseManager $database) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthCheckResultData
    {
        $this->database->connection()->select('select 1');

        return HealthCheckResultData::ok();
    }
}
