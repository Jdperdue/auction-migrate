<?php

declare(strict_types=1);

class StepConfig
{
    public ?int $tenantId;
    public int $batchSize;
    public ?int $systemUserId;

    public function __construct(?int $tenantId, int $batchSize, ?int $systemUserId)
    {
        $this->tenantId = $tenantId;
        $this->batchSize = $batchSize;
        $this->systemUserId = $systemUserId;
    }
}
