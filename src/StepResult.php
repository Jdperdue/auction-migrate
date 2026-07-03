<?php

declare(strict_types=1);

class StepResult
{
    public int $processed = 0;
    public int $inserted = 0;
    public int $skipped = 0;
    public array $errors = [];

    public function merge(StepResult $other): void
    {
        $this->processed += $other->processed;
        $this->inserted += $other->inserted;
        $this->skipped += $other->skipped;
        $this->errors = array_merge($this->errors, $other->errors);
    }
}
