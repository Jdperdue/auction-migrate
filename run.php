<?php

declare(strict_types=1);

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Support.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/MigrationMap.php';
require __DIR__ . '/src/StepResult.php';
require __DIR__ . '/src/StepInterface.php';
require __DIR__ . '/src/StepConfig.php';
require __DIR__ . '/src/steps/01_Tenant.php';
require __DIR__ . '/src/steps/02_Categories.php';
require __DIR__ . '/src/steps/03_Clients.php';
require __DIR__ . '/src/steps/04_Sellers.php';
require __DIR__ . '/src/steps/05_Bidders.php';
require __DIR__ . '/src/steps/06_BidderTenant.php';
require __DIR__ . '/src/steps/07_BidderDeposits.php';
require __DIR__ . '/src/steps/08_BidderNotes.php';
require __DIR__ . '/src/steps/09_Auctions.php';
require __DIR__ . '/src/steps/10_Lots.php';
require __DIR__ . '/src/steps/11_Bids.php';
require __DIR__ . '/src/steps/12_Finalize.php';
require __DIR__ . '/src/steps/13_LotPhotos.php';
require __DIR__ . '/src/steps/14_ClientTemplates.php';
require __DIR__ . '/src/steps/15_ClientLocations.php';

/**
 * Ordered migration steps. `transactional` = true means run.php wraps the
 * step's target-side work in a single BEGIN/COMMIT. Steps 10 and 11 manage
 * their own per-chunk transactions internally (BATCH_SIZE is too large for
 * a single transaction), so they are excluded here.
 */
$STEPS = [
    '01_Tenant' => ['class' => Step01Tenant::class, 'transactional' => true, 'requiresTenant' => false],
    '02_Categories' => ['class' => Step02Categories::class, 'transactional' => true, 'requiresTenant' => false],
    '03_Clients' => ['class' => Step03Clients::class, 'transactional' => true, 'requiresTenant' => true],
    '04_Sellers' => ['class' => Step04Sellers::class, 'transactional' => true, 'requiresTenant' => true],
    '05_Bidders' => ['class' => Step05Bidders::class, 'transactional' => true, 'requiresTenant' => true],
    '06_BidderTenant' => ['class' => Step06BidderTenant::class, 'transactional' => true, 'requiresTenant' => true],
    '07_BidderDeposits' => ['class' => Step07BidderDeposits::class, 'transactional' => true, 'requiresTenant' => true],
    '08_BidderNotes' => ['class' => Step08BidderNotes::class, 'transactional' => true, 'requiresTenant' => true],
    '09_Auctions' => ['class' => Step09Auctions::class, 'transactional' => true, 'requiresTenant' => true],
    '10_Lots' => ['class' => Step10Lots::class, 'transactional' => false, 'requiresTenant' => true],
    '11_Bids' => ['class' => Step11Bids::class, 'transactional' => false, 'requiresTenant' => true],
    '12_Finalize' => ['class' => Step12Finalize::class, 'transactional' => true, 'requiresTenant' => true],
    '13_LotPhotos' => ['class' => Step13LotPhotos::class, 'transactional' => false, 'requiresTenant' => true],
    '14_ClientTemplates' => ['class' => Step14ClientTemplates::class, 'transactional' => true, 'requiresTenant' => true],
    '15_ClientLocations' => ['class' => Step15ClientLocations::class, 'transactional' => false, 'requiresTenant' => true],
];

function parseArgs(array $argv): array
{
    $options = ['dry-run' => false, 'step' => null];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry-run'] = true;
        } elseif (str_starts_with($arg, '--step=')) {
            $options['step'] = substr($arg, strlen('--step='));
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        }
    }

    return $options;
}

function runStep(string $key, array $definition, PDO $source, PDO $target, bool $dryRun, Logger $baseLogger, StepConfig $config): StepResult
{
    $logger = $baseLogger->withStep($key);
    $class = $definition['class'];

    if ($definition['requiresTenant'] && $config->tenantId === null) {
        $logger->error('TENANT_ID is not set in .env. Run 01_Tenant first, then set TENANT_ID before running this step.');
        $result = new StepResult();
        $result->errors[] = 'Missing TENANT_ID';

        return $result;
    }

    /** @var StepInterface $step */
    $step = new $class($logger, $config);

    $useTransaction = $definition['transactional'] && !$dryRun;

    if ($useTransaction) {
        $target->beginTransaction();
    }

    try {
        $result = $step->run($source, $target, $dryRun);

        if ($useTransaction) {
            if (!empty($result->errors)) {
                $target->rollBack();
                $logger->error('Step reported errors — rolling back transaction.');
            } else {
                $target->commit();
            }
        }
    } catch (Throwable $e) {
        if ($useTransaction && $target->inTransaction()) {
            $target->rollBack();
        }
        $logger->error('Uncaught exception: ' . $e->getMessage());
        $result = new StepResult();
        $result->errors[] = $e->getMessage();
    }

    $logger->info(sprintf(
        'processed=%d inserted=%d skipped=%d errors=%d',
        $result->processed,
        $result->inserted,
        $result->skipped,
        count($result->errors)
    ));

    return $result;
}

function main(array $argv): int
{
    global $STEPS;

    $options = parseArgs($argv);

    if (!empty($options['help'])) {
        echo <<<HELP
        Usage:
          php run.php [--dry-run] [--step=NN_Name]

        Examples:
          php run.php --dry-run
          php run.php
          php run.php --step=10_Lots
          php run.php --step=03_Clients --dry-run

        HELP;

        return 0;
    }

    Env::load(__DIR__ . '/.env');

    $dryRun = $options['dry-run'] || filter_var($_ENV['DRY_RUN'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $logFile = __DIR__ . '/' . ($_ENV['LOG_FILE'] ?? 'logs/migration.log');
    $batchSize = (int) ($_ENV['BATCH_SIZE'] ?? 500);
    $tenantId = (isset($_ENV['TENANT_ID']) && $_ENV['TENANT_ID'] !== '') ? (int) $_ENV['TENANT_ID'] : null;
    $systemUserId = (isset($_ENV['MIGRATION_SYSTEM_USER_ID']) && $_ENV['MIGRATION_SYSTEM_USER_ID'] !== '')
        ? (int) $_ENV['MIGRATION_SYSTEM_USER_ID']
        : null;

    $config = new StepConfig($tenantId, $batchSize, $systemUserId);
    $baseLogger = new Logger($logFile);

    $baseLogger->info($dryRun ? 'Starting migration run in DRY-RUN mode.' : 'Starting LIVE migration run.');

    $source = Database::source();
    $target = Database::target();

    if ($options['step'] !== null) {
        if (!isset($STEPS[$options['step']])) {
            $baseLogger->error("Unknown step: {$options['step']}");

            return 1;
        }

        $result = runStep($options['step'], $STEPS[$options['step']], $source, $target, $dryRun, $baseLogger, $config);

        return empty($result->errors) ? 0 : 1;
    }

    $exitCode = 0;

    foreach ($STEPS as $key => $definition) {
        $result = runStep($key, $definition, $source, $target, $dryRun, $baseLogger, $config);

        if (!empty($result->errors)) {
            $baseLogger->error("Step {$key} failed — stopping migration run.");
            $exitCode = 1;
            break;
        }
    }

    $baseLogger->info('Migration run finished.');

    return $exitCode;
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
