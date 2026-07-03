<?php

declare(strict_types=1);

interface StepInterface
{
    public function run(PDO $source, PDO $target, bool $dryRun): StepResult;
}
