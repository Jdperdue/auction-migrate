<?php

declare(strict_types=1);

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Support.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/MigrationMap.php';
require __DIR__ . '/src/StepConfig.php';

/**
 * Wipes tenant 1's (René Bates) migrated content from the target so a fresh
 * `php run.php` reproduces a clean import against current admin_live data.
 * This is the "dump" half of the refresh workflow described in README.md —
 * every refresh fully removes and replaces tenant content, since the source
 * ages out and needs to stay demo-fresh.
 *
 * Deliberately NOT part of run.php's $STEPS — destructive tooling gets its
 * own entry point, never reachable via --step=.
 *
 * Safety model is inverted from run.php: the default (no flags) is a dry
 * preview that only prints counts. Nothing is deleted without --confirm.
 *
 * Scope is every table with tenant_id -> tenants.id ON DELETE CASCADE,
 * EXCEPT `tenants`, `tenant_domains`, `users` and `categories` — `users` rows
 * under tenant 1 are real platform logins (operator/admin accounts), not
 * migrated content, and `categories` is a shared global lookup table. Explicit
 * deletes below are the roots; everything else cascades from them.
 */

const ROOT_TABLES = ['auctions', 'bidders', 'clients', 'tax_jurisdictions', 'bid_increment_sets', 'deposit_tier_sets'];

function parseArgs(array $argv): array
{
    $options = ['confirm' => false];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--confirm') {
            $options['confirm'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        }
    }

    return $options;
}

/**
 * @return array<string, int>
 */
function countByTenant(PDO $target, int $tenantId): array
{
    $counts = [];
    foreach (ROOT_TABLES as $table) {
        $stmt = $target->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = :tenant_id");
        $stmt->execute(['tenant_id' => $tenantId]);
        $counts[$table] = (int) $stmt->fetchColumn();
    }

    return $counts;
}

/**
 * @return int[]
 */
function fetchIds(PDO $target, string $table, int $tenantId): array
{
    $stmt = $target->prepare("SELECT id FROM `{$table}` WHERE tenant_id = :tenant_id");
    $stmt->execute(['tenant_id' => $tenantId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return string[]
 */
function fetchBidderEmails(PDO $target, int $tenantId): array
{
    $stmt = $target->prepare("SELECT email FROM bidders WHERE tenant_id = :tenant_id AND email IS NOT NULL");
    $stmt->execute(['tenant_id' => $tenantId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function removeStorageDirs(string $targetStorageDir, string $subdir, array $ids, Logger $logger): int
{
    $removed = 0;
    foreach ($ids as $id) {
        $dir = "{$targetStorageDir}/{$subdir}/{$id}";
        if (is_dir($dir)) {
            removeDirRecursive($dir);
            $removed++;
        }
    }
    $logger->info("Removed {$removed} {$subdir}/ director" . ($removed === 1 ? 'y' : 'ies') . " under {$targetStorageDir}.");

    return $removed;
}

function removeDirRecursive(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = "{$dir}/{$item}";
        if (is_dir($path)) {
            removeDirRecursive($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

function main(array $argv): int
{
    $options = parseArgs($argv);

    if (!empty($options['help'])) {
        echo <<<HELP
        Usage:
          php reset.php            # dry preview — prints counts, deletes nothing
          php reset.php --confirm  # actually wipes tenant content

        Wipes TENANT_ID's migrated content (clients, sellers, bidders, auctions,
        lots, bids, and everything cascading from them) so a fresh `php run.php`
        reproduces a clean import. Never touches tenants, tenant_domains, users,
        or categories.

        HELP;

        return 0;
    }

    Env::load(__DIR__ . '/.env');

    $logFile = __DIR__ . '/' . ($_ENV['LOG_FILE'] ?? 'logs/migration.log');
    $logger = (new Logger($logFile))->withStep('RESET');
    $tenantId = (isset($_ENV['TENANT_ID']) && $_ENV['TENANT_ID'] !== '') ? (int) $_ENV['TENANT_ID'] : null;

    if ($tenantId === null) {
        $logger->error('TENANT_ID is not set in .env — refusing to run.');

        return 1;
    }

    $target = Database::target();
    $targetStorageDir = rtrim((string) ($_ENV['TARGET_STORAGE_DIR'] ?? ''), '/');

    $counts = countByTenant($target, $tenantId);
    $clientIds = fetchIds($target, 'clients', $tenantId);
    $lotIds = fetchIds($target, 'lots', $tenantId);
    $bidderEmails = fetchBidderEmails($target, $tenantId);

    if (!$options['confirm']) {
        $logger->info("DRY PREVIEW for tenant_id={$tenantId} — nothing will be deleted. Pass --confirm to execute.");
        foreach ($counts as $table => $count) {
            $logger->dryRun("Would delete {$count} row(s) from `{$table}` (and everything cascading from them).");
        }
        $logger->dryRun('Would delete ' . count($clientIds) . ' client logo director' . (count($clientIds) === 1 ? 'y' : 'ies') . " under {$targetStorageDir}/clients/.");
        $logger->dryRun('Would delete ' . count($lotIds) . ' lot photo director' . (count($lotIds) === 1 ? 'y' : 'ies') . " under {$targetStorageDir}/lots/.");
        $logger->dryRun('Would delete bidder_password_reset_tokens rows for ' . count($bidderEmails) . ' captured bidder email(s).');
        $logger->dryRun('Would drop _migration_lot_map.');

        return 0;
    }

    if ($targetStorageDir === '') {
        $logger->error('TARGET_STORAGE_DIR must be set in .env — refusing to run --confirm without it.');

        return 1;
    }

    $logger->info("LIVE RESET for tenant_id={$tenantId} — deleting migrated content.");

    $target->beginTransaction();

    try {
        foreach (ROOT_TABLES as $table) {
            $stmt = $target->prepare("DELETE FROM `{$table}` WHERE tenant_id = :tenant_id");
            $stmt->execute(['tenant_id' => $tenantId]);
            $logger->info("Deleted {$stmt->rowCount()} row(s) from `{$table}`.");
        }

        if (!empty($bidderEmails)) {
            $placeholders = implode(',', array_fill(0, count($bidderEmails), '?'));
            $stmt = $target->prepare("DELETE FROM bidder_password_reset_tokens WHERE email IN ({$placeholders})");
            $stmt->execute($bidderEmails);
            $logger->info("Deleted {$stmt->rowCount()} row(s) from `bidder_password_reset_tokens`.");
        }

        $target->commit();
    } catch (Throwable $e) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        $logger->error('Reset failed — rolled back, nothing was deleted: ' . $e->getMessage());

        return 1;
    }

    (new MigrationMap($target))->drop();
    $logger->info('Dropped _migration_lot_map.');

    removeStorageDirs($targetStorageDir, 'clients', $clientIds, $logger);
    removeStorageDirs($targetStorageDir, 'lots', $lotIds, $logger);

    $logger->info('Reset complete. Run `php run.php` to re-populate from current admin_live data.');

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
