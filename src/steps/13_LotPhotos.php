<?php

declare(strict_types=1);

/**
 * Copies lot image files from the René Bates server's per-auction photo
 * folders (SOURCE_PHOTOS_DIR/bates{NNN}/) into the target app's public
 * storage disk (TARGET_STORAGE_DIR/lots/{lot_id}/), and rewrites
 * lot_images.path from the bare source filename to the disk-relative path
 * the app expects ("lots/{lot_id}/{filename}").
 *
 * Source filenames are inconsistently cased across folders even though the
 * filesystem is case-sensitive, so each bates folder is indexed once
 * (lowercase filename => actual on-disk filename) and matched case-
 * insensitively. Thumbnail files (T_ prefix) are never copied — the target
 * app has no thumbnail concept.
 *
 * Ends with a coverage validation pass: re-checks every lot_images row
 * against the filesystem and logs the count of distinct lots with zero
 * resolved photos — the actionable number (a lot missing 1 of 6 photos is
 * fine; missing all 6 isn't), surfaced as a clear summary instead of a
 * scattered per-file skip count in the log.
 */
class Step13LotPhotos implements StepInterface
{
    private Logger $logger;
    private StepConfig $config;

    /** @var array<string, array<string, string>> auction prefix => [lowercase filename => actual filename] */
    private array $dirIndexCache = [];

    public function __construct(Logger $logger, StepConfig $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function run(PDO $source, PDO $target, bool $dryRun): StepResult
    {
        $result = new StepResult();

        $sourcePhotosDir = rtrim((string) ($_ENV['SOURCE_PHOTOS_DIR'] ?? ''), '/');
        $targetStorageDir = rtrim((string) ($_ENV['TARGET_STORAGE_DIR'] ?? ''), '/');

        if ($sourcePhotosDir === '' || $targetStorageDir === '') {
            $this->logger->error('SOURCE_PHOTOS_DIR and TARGET_STORAGE_DIR must both be set in .env.');
            $result->errors[] = 'Missing photo directory config';

            return $result;
        }

        if (!Support::tableExists($target, '_migration_lot_map')) {
            $this->logger->error('_migration_lot_map not found — run step 10 (Lots) before this step.');
            $result->errors[] = 'Missing _migration_lot_map';

            return $result;
        }

        $rows = $target->query(
            'SELECT li.id AS image_id, li.lot_id AS lot_id, li.path AS path, m.source_auction AS source_auction
             FROM lot_images li
             JOIN _migration_lot_map m ON m.target_lot_id = li.lot_id
             ORDER BY li.lot_id, li.sort_order'
        )->fetchAll();

        $updateStmt = $target->prepare('UPDATE lot_images SET path = :path, updated_at = NOW() WHERE id = :id');

        foreach ($rows as $row) {
            $result->processed++;
            $this->processImage($row, $sourcePhotosDir, $targetStorageDir, $updateStmt, $dryRun, $result);
        }

        $this->logger->info("LotPhotos: {$result->processed} processed, {$result->inserted} copied, {$result->skipped} skipped.");

        $this->verifyPhotoCoverage($target, $targetStorageDir, $dryRun);

        return $result;
    }

    /**
     * Re-checks every lot_images row for this tenant against the filesystem
     * and reports the count of distinct lots with zero resolved photos. In
     * dry-run mode this reflects pre-copy state (a preview of the gap);
     * in a live run it reflects what actually landed on disk.
     */
    private function verifyPhotoCoverage(PDO $target, string $targetStorageDir, bool $dryRun): void
    {
        $totalLotsStmt = $target->prepare('SELECT COUNT(*) FROM lots WHERE tenant_id = :tenant_id');
        $totalLotsStmt->execute(['tenant_id' => $this->config->tenantId]);
        $totalLots = (int) $totalLotsStmt->fetchColumn();

        $stmt = $target->prepare(
            'SELECT li.lot_id AS lot_id, li.path AS path
             FROM lot_images li
             JOIN lots l ON l.id = li.lot_id
             WHERE l.tenant_id = :tenant_id'
        );
        $stmt->execute(['tenant_id' => $this->config->tenantId]);

        $totalRows = 0;
        $resolvedRows = 0;
        $lotsSeen = [];
        $lotsResolved = [];

        foreach ($stmt->fetchAll() as $row) {
            $totalRows++;
            $lotId = (int) $row['lot_id'];
            $lotsSeen[$lotId] = true;

            if (is_file("{$targetStorageDir}/{$row['path']}")) {
                $resolvedRows++;
                $lotsResolved[$lotId] = true;
            }
        }

        $lotsWithNoImageRows = $totalLots - count($lotsSeen);
        $lotsWithAllUnresolved = count($lotsSeen) - count($lotsResolved);
        $lotsWithZeroPhotos = $lotsWithNoImageRows + $lotsWithAllUnresolved;
        $mode = $dryRun ? 'pre-copy' : 'post-copy';

        $this->logger->info(
            "Photo coverage ({$mode}): {$resolvedRows}/{$totalRows} lot_images row(s) resolve to a real file; "
            . count($lotsResolved) . "/{$totalLots} lot(s) have at least one working photo."
        );

        if ($lotsWithZeroPhotos > 0) {
            $this->logger->warn(
                "Photo coverage: {$lotsWithZeroPhotos} of {$totalLots} lot(s) have ZERO working photos "
                . "({$lotsWithNoImageRows} with no lot_images row at all, {$lotsWithAllUnresolved} whose "
                . 'images all failed to resolve) — their source files were not found under SOURCE_PHOTOS_DIR. '
                . 'Review the per-image skip warnings above and confirm against the source bates folders.'
            );
        }
    }

    private function processImage(
        array $row,
        string $sourcePhotosDir,
        string $targetStorageDir,
        PDOStatement $updateStmt,
        bool $dryRun,
        StepResult $result
    ): void {
        $imageId = (int) $row['image_id'];
        $lotId = (int) $row['lot_id'];
        $currentPath = (string) $row['path'];
        $prefix = str_pad((string) (int) $row['source_auction'], 3, '0', STR_PAD_LEFT);

        if (str_starts_with($currentPath, 'lots/')) {
            if (is_file("{$targetStorageDir}/{$currentPath}")) {
                $result->skipped++;

                return;
            }
            $filename = basename($currentPath);
        } else {
            $filename = $currentPath;
        }

        $batesDir = "{$sourcePhotosDir}/bates{$prefix}";
        $index = $this->indexForAuction($prefix, $batesDir);

        $lookupKey = strtolower($filename);
        if (!isset($index[$lookupKey])) {
            $this->logger->warn("Lot {$lotId} image \"{$filename}\" not found in {$batesDir} — leaving unresolved.");
            $result->skipped++;

            return;
        }

        $actualFilename = $index[$lookupKey];
        $sourceFile = "{$batesDir}/{$actualFilename}";
        $newPath = "lots/{$lotId}/{$actualFilename}";
        $destFile = "{$targetStorageDir}/{$newPath}";

        if ($dryRun) {
            $this->logger->dryRun("Would copy {$sourceFile} -> {$destFile}; lot_images.id={$imageId} path -> {$newPath}");
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

        $updateStmt->execute(['path' => $newPath, 'id' => $imageId]);
        $result->inserted++;
    }

    /**
     * @return array<string, string> lowercase filename => actual on-disk filename
     */
    private function indexForAuction(string $prefix, string $batesDir): array
    {
        if (isset($this->dirIndexCache[$prefix])) {
            return $this->dirIndexCache[$prefix];
        }

        $index = [];

        if (!is_dir($batesDir)) {
            $this->logger->warn("Source photo directory not found: {$batesDir}");
            $this->dirIndexCache[$prefix] = $index;

            return $index;
        }

        foreach (scandir($batesDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (stripos($entry, 'T_') === 0) {
                continue;
            }
            $index[strtolower($entry)] = $entry;
        }

        $this->dirIndexCache[$prefix] = $index;

        return $index;
    }
}
