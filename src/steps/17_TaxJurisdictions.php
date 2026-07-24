<?php

declare(strict_types=1);

/**
 * Seeds tax_jurisdictions from g_tax_classes (one row per distinct source
 * class, idempotent by name+tenant), then loops the 300 {NNN}_inventory
 * table sets and sets lots.tax_jurisdiction_id from each lot's
 * tax_class_id — a clean FK-for-FK swap, no inference needed. Runs after
 * step 10 (Lots), same _migration_lot_map dependency as steps 13/15.
 */
class Step17TaxJurisdictions implements StepInterface
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

        $classIdToJurisdictionId = $this->seedJurisdictions($source, $target, $dryRun, $result);
        $this->linkLots($source, $target, $dryRun, $result, $classIdToJurisdictionId);

        $this->logger->info("TaxJurisdictions: {$result->processed} processed, {$result->inserted} inserted/updated, {$result->skipped} skipped.");

        return $result;
    }

    /**
     * @return array<int, int> source g_tax_classes.ID => target tax_jurisdictions.id
     */
    private function seedJurisdictions(PDO $source, PDO $target, bool $dryRun, StepResult $result): array
    {
        $rows = $source->query('SELECT ID AS id, tax_description AS description, tax_rate AS rate FROM g_tax_classes ORDER BY ID')->fetchAll();

        $findStmt = $target->prepare('SELECT id FROM tax_jurisdictions WHERE tenant_id = :tenant_id AND name = :name');
        $insertStmt = $target->prepare(
            'INSERT INTO tax_jurisdictions (tenant_id, name, rate, is_non_taxable, is_active, created_at, updated_at)
             VALUES (:tenant_id, :name, :rate, :is_non_taxable, 1, NOW(), NOW())'
        );

        $map = [];

        foreach ($rows as $row) {
            $sourceId = (int) $row['id'];
            $name = (string) $row['description'];
            $rate = (float) $row['rate'];
            $isNonTaxable = $rate === 0.0 ? 1 : 0;

            $findStmt->execute(['tenant_id' => $this->config->tenantId, 'name' => $name]);
            $existingId = $findStmt->fetchColumn();

            if ($existingId !== false) {
                $map[$sourceId] = (int) $existingId;
                continue;
            }

            if ($dryRun) {
                $this->logger->dryRun("Would insert tax_jurisdiction name=\"{$name}\" rate={$rate}");
                $map[$sourceId] = -1;
                continue;
            }

            $insertStmt->execute([
                'tenant_id' => $this->config->tenantId,
                'name' => $name,
                'rate' => $rate,
                'is_non_taxable' => $isNonTaxable,
            ]);
            $map[$sourceId] = (int) $target->lastInsertId();
        }

        return $map;
    }

    /**
     * @param array<int, int> $classIdToJurisdictionId
     */
    private function linkLots(PDO $source, PDO $target, bool $dryRun, StepResult $result, array $classIdToJurisdictionId): void
    {
        $mapStmt = $target->prepare(
            'SELECT target_lot_id FROM _migration_lot_map WHERE source_auction = :auction AND source_lot_id = :source_lot_id'
        );
        $updateStmt = $target->prepare(
            'UPDATE lots SET tax_jurisdiction_id = :jurisdiction_id, updated_at = NOW() WHERE id = :id AND tax_jurisdiction_id IS NULL'
        );

        $prefixes = Support::sourceAuctionPrefixes($source);
        $this->logger->info(count($prefixes) . ' auction table set(s) found in source.');

        foreach ($prefixes as $prefix) {
            $invTable = "{$prefix}_inventory";
            if (!Support::tableExists($source, $invTable)) {
                continue;
            }

            $auctionNum = Support::auctionNumberFromPrefix($prefix);
            $rows = $source->query("SELECT ID AS inv_id, tax_class_id FROM {$invTable} ORDER BY ID")->fetchAll();

            foreach ($rows as $row) {
                $result->processed++;
                $sourceLotId = (int) $row['inv_id'];
                $taxClassId = $row['tax_class_id'] !== null ? (int) $row['tax_class_id'] : null;

                if ($taxClassId === null || !isset($classIdToJurisdictionId[$taxClassId])) {
                    $this->logger->warn("Auction {$auctionNum} lot {$sourceLotId}: tax_class_id={$taxClassId} has no resolved jurisdiction — skipping.");
                    $result->skipped++;
                    continue;
                }

                $mapStmt->execute(['auction' => $auctionNum, 'source_lot_id' => $sourceLotId]);
                $targetLotId = $mapStmt->fetchColumn();
                if ($targetLotId === false) {
                    $result->skipped++;
                    continue;
                }

                $jurisdictionId = $classIdToJurisdictionId[$taxClassId];

                if ($dryRun) {
                    $this->logger->dryRun("Would set lots.id={$targetLotId} tax_jurisdiction_id=" . ($jurisdictionId === -1 ? 'NEW' : $jurisdictionId));
                    $result->inserted++;
                    continue;
                }

                $updateStmt->execute(['jurisdiction_id' => $jurisdictionId, 'id' => (int) $targetLotId]);
                $result->inserted++;
            }
        }
    }
}
