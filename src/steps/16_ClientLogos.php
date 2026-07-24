<?php

declare(strict_types=1);

/**
 * Copies client logo files from the René Bates server's flat logo directory
 * (SOURCE_LOGOS_DIR/logo_{client_id:%06d}.jpg) into the target app's public
 * storage disk (TARGET_STORAGE_DIR/clients/{client_id}/logo.jpg), and sets
 * clients.logo_path accordingly.
 *
 * Unlike lot photos, client IDs are preserved 1:1 from c_clients.ID (see
 * step 03), so this is a direct per-client existence check — no ID mapping
 * table needed. Clients that already have a logo_path (e.g. an operator
 * uploaded one through the app before this step ran) are left untouched,
 * so this step never clobbers a real upload.
 */
class Step16ClientLogos implements StepInterface
{
    private Logger $logger;
    private StepConfig $config;

    public function __construct(Logger $logger, StepConfig $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function run(PDO $source, PDO $target, bool $dryRun): StepResult
    {
        $result = new StepResult();

        $sourceLogosDir = rtrim((string) ($_ENV['SOURCE_LOGOS_DIR'] ?? ''), '/');
        $targetStorageDir = rtrim((string) ($_ENV['TARGET_STORAGE_DIR'] ?? ''), '/');

        if ($sourceLogosDir === '' || $targetStorageDir === '') {
            $this->logger->error('SOURCE_LOGOS_DIR and TARGET_STORAGE_DIR must both be set in .env.');
            $result->errors[] = 'Missing logo directory config';

            return $result;
        }

        $stmt = $target->prepare(
            'SELECT id, logo_path FROM clients WHERE tenant_id = :tenant_id ORDER BY id'
        );
        $stmt->execute(['tenant_id' => $this->config->tenantId]);
        $rows = $stmt->fetchAll();

        $updateStmt = $target->prepare('UPDATE clients SET logo_path = :path, updated_at = NOW() WHERE id = :id');

        foreach ($rows as $row) {
            $result->processed++;
            $this->processClient($row, $sourceLogosDir, $targetStorageDir, $updateStmt, $dryRun, $result);
        }

        $this->logger->info("ClientLogos: {$result->processed} processed, {$result->inserted} copied, {$result->skipped} skipped.");

        return $result;
    }

    private function processClient(
        array $row,
        string $sourceLogosDir,
        string $targetStorageDir,
        PDOStatement $updateStmt,
        bool $dryRun,
        StepResult $result
    ): void {
        $clientId = (int) $row['id'];

        if ($row['logo_path'] !== null) {
            $result->skipped++;

            return;
        }

        $sourceFile = sprintf('%s/logo_%06d.jpg', $sourceLogosDir, $clientId);

        if (!is_file($sourceFile)) {
            $result->skipped++;

            return;
        }

        $newPath = "clients/{$clientId}/logo.jpg";
        $destFile = "{$targetStorageDir}/{$newPath}";

        if ($dryRun) {
            $this->logger->dryRun("Would copy {$sourceFile} -> {$destFile}; clients.id={$clientId} logo_path -> {$newPath}");
            $result->inserted++;

            return;
        }

        $destDir = dirname($destFile);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            $this->logger->error("Failed to create directory {$destDir}");
            $result->errors[] = "mkdir failed: {$destDir}";

            return;
        }

        if (!copy($sourceFile, $destFile)) {
            $this->logger->error("Failed to copy {$sourceFile} -> {$destFile}");
            $result->errors[] = "copy failed: {$sourceFile}";

            return;
        }

        chmod($destFile, 0644);

        $updateStmt->execute(['path' => $newPath, 'id' => $clientId]);
        $result->inserted++;
    }
}
