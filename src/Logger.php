<?php

declare(strict_types=1);

class Logger
{
    private string $logFile;
    private string $step;

    public function __construct(string $logFile, string $step = 'MIGRATE')
    {
        $this->logFile = $logFile;
        $this->step = $step;

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function withStep(string $step): self
    {
        return new self($this->logFile, $step);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function dryRun(string $message): void
    {
        $this->write('DRY_RUN', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf(
            '[%s] [%s] [%s] %s',
            date('Y-m-d H:i:s'),
            $level,
            $this->step,
            $message
        );

        echo $line . PHP_EOL;
        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND);
    }
}
