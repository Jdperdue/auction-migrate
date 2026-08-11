<?php

declare(strict_types=1);

/**
 * Migrates c_consignors into consignors, preserving source IDs 1:1 (same
 * shape as step 03 Clients — a single shared source table, not one of the
 * 300 per-auction table sets, so there's no collision problem to solve with
 * a mapping table). Then loops the 300 {NNN}_inventory table sets and sets
 * lots.consignor_id from each lot's consignor_id. Runs after step 10 (Lots),
 * same _migration_lot_map dependency as steps 13/15/17.
 */
class Step18Consignors implements StepInterface
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

        if (!Support::tableExists($target, '_migration_lot_map')) {
            $this->logger->error('_migration_lot_map not found — run step 10 (Lots) before this step.');
            $result->errors[] = 'Missing _migration_lot_map';

            return $result;
        }

        $migratedIds = $this->migrateConsignors($source, $target, $dryRun, $result);
        $this->linkLots($source, $target, $dryRun, $result, $migratedIds);

        $this->logger->info("Consignors: {$result->processed} processed, {$result->inserted} inserted/updated, {$result->skipped} skipped.");

        return $result;
    }

    /**
     * @return array<int, bool> set of target consignor ids now present (migrated this run or already existing)
     */
    private function migrateConsignors(PDO $source, PDO $target, bool $dryRun, StepResult $result): array
    {
        $rows = $source->query('SELECT ID AS id, client_id, description FROM c_consignors ORDER BY ID')->fetchAll();

        $checkStmt = $target->prepare('SELECT id FROM consignors WHERE id = :id');
        $clientCheckStmt = $target->prepare('SELECT id FROM clients WHERE id = :id');
        $slugCheckStmt = $target->prepare('SELECT 1 FROM consignors WHERE client_id = :client_id AND slug = :slug');
        $insertStmt = $target->prepare(
            'INSERT INTO consignors (id, tenant_id, client_id, name, slug, status, created_at, updated_at)
             VALUES (:id, :tenant_id, :client_id, :name, :slug, \'active\', NOW(), NOW())'
        );

        $present = [];

        foreach ($rows as $row) {
            $result->processed++;
            $id = (int) $row['id'];
            $clientId = (int) $row['client_id'];

            $checkStmt->execute(['id' => $id]);
            if ($checkStmt->fetchColumn() !== false) {
                $present[$id] = true;
                $result->skipped++;
                continue;
            }

            $clientCheckStmt->execute(['id' => $clientId]);
            if ($clientCheckStmt->fetchColumn() === false) {
                $this->logger->warn("Consignor id={$id} references missing client_id={$clientId} — skipping. Ensure step 03 (Clients) ran first.");
                $result->skipped++;
                continue;
            }

            if ($dryRun) {
                $this->logger->dryRun("Would insert consignor id={$id} client_id={$clientId} name=\"{$row['description']}\"");
                $present[$id] = true;
                $result->inserted++;
                continue;
            }

            $insertStmt->execute([
                'id' => $id,
                'tenant_id' => $this->config->tenantId,
                'client_id' => $clientId,
                'name' => $row['description'],
                'slug' => $this->uniqueSlug($slugCheckStmt, $clientId, Support::slugify((string) $row['description'])),
            ]);
            $present[$id] = true;
            $result->inserted++;
        }

        return $present;
    }

    /**
     * consignors has a (client_id, slug) unique constraint (added
     * 2026-08-02) — appends -2, -3, ... on collision.
     */
    private function uniqueSlug(PDOStatement $slugCheckStmt, int $clientId, string $baseSlug): string
    {
        $slug = $baseSlug;
        $suffix = 2;
        while (true) {
            $slugCheckStmt->execute(['client_id' => $clientId, 'slug' => $slug]);
            if ($slugCheckStmt->fetchColumn() === false) {
                return $slug;
            }
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }
    }

    /**
     * @param array<int, bool> $migratedIds
     */
    private function linkLots(PDO $source, PDO $target, bool $dryRun, StepResult $result, array $migratedIds): void
    {
        $mapStmt = $target->prepare(
            'SELECT target_lot_id FROM _migration_lot_map WHERE source_auction = :auction AND source_lot_id = :source_lot_id'
        );
        $updateStmt = $target->prepare(
            'UPDATE lots SET consignor_id = :consignor_id, updated_at = NOW() WHERE id = :id AND consignor_id IS NULL'
        );

        $prefixes = Support::sourceAuctionPrefixes($source);

        foreach ($prefixes as $prefix) {
            $invTable = "{$prefix}_inventory";
            if (!Support::tableExists($source, $invTable)) {
                continue;
            }

            $auctionNum = Support::auctionNumberFromPrefix($prefix);
            $rows = $source->query("SELECT ID AS inv_id, consignor_id FROM {$invTable} ORDER BY ID")->fetchAll();

            foreach ($rows as $row) {
                $result->processed++;
                $sourceLotId = (int) $row['inv_id'];
                $consignorId = $row['consignor_id'] !== null ? (int) $row['consignor_id'] : null;

                if ($consignorId === null) {
                    $result->skipped++;
                    continue;
                }

                if (!isset($migratedIds[$consignorId])) {
                    $this->logger->warn("Auction {$auctionNum} lot {$sourceLotId}: consignor_id={$consignorId} was not migrated — skipping.");
                    $result->skipped++;
                    continue;
                }

                $mapStmt->execute(['auction' => $auctionNum, 'source_lot_id' => $sourceLotId]);
                $targetLotId = $mapStmt->fetchColumn();
                if ($targetLotId === false) {
                    $result->skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->logger->dryRun("Would set lots.id={$targetLotId} consignor_id={$consignorId}");
                    $result->inserted++;
                    continue;
                }

                $updateStmt->execute(['consignor_id' => $consignorId, 'id' => (int) $targetLotId]);
                $result->inserted++;
            }
        }
    }
}
