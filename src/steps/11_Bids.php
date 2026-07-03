<?php

declare(strict_types=1);

/**
 * Iterates the 300 possible {NNN}_bidder_activity source tables and
 * migrates accepted bids into `bids`, resolving lot_id via
 * _migration_lot_map (populated by step 10). After all bids for an
 * auction are inserted, marks the highest bid per lot as is_winning.
 *
 * `type = 'attempt'` rows (rejected bids) and `is_removed = 1` rows
 * (retracted bids) are skipped per docs/migration-renebates.md.
 *
 * Processed in chunks of BATCH_SIZE — not a single transaction.
 */
class Step11Bids implements StepInterface
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

        if (!$dryRun && !Support::tableExists($target, '_migration_lot_map')) {
            $this->logger->error('_migration_lot_map table does not exist — run step 10 (Lots) first.');
            $result->errors[] = 'Missing _migration_lot_map';

            return $result;
        }

        $map = new MigrationMap($target);
        $bidderCheckStmt = $target->prepare('SELECT id FROM bidders WHERE id = :id');

        $prefixes = Support::sourceAuctionPrefixes($source);
        $this->logger->info(count($prefixes) . ' auction table set(s) found in source.');

        foreach ($prefixes as $prefix) {
            $this->processAuction($prefix, $source, $target, $map, $dryRun, $result, $bidderCheckStmt);
        }

        $this->logger->info("Bids: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    private function processAuction(
        string $prefix,
        PDO $source,
        PDO $target,
        MigrationMap $map,
        bool $dryRun,
        StepResult $result,
        PDOStatement $bidderCheckStmt
    ): void {
        $auctionNum = Support::auctionNumberFromPrefix($prefix);
        $baTable = "{$prefix}_bidder_activity";

        if (!Support::tableExists($source, $baTable)) {
            $this->logger->warn("{$baTable} does not exist — skipping auction {$prefix}.");

            return;
        }

        $batchSize = $this->config->batchSize;
        $offset = 0;
        $anyInserted = false;

        $insertStmt = $target->prepare(
            'INSERT INTO bids (
                lot_id, auction_id, tenant_id, bidder_id, amount,
                ip_address, is_winning, created_at
             ) VALUES (
                :lot_id, :auction_id, :tenant_id, :bidder_id, :amount,
                :ip_address, 0, :created_at
             )'
        );

        while (true) {
            $sql = "SELECT
                        ID AS id, inventory_id, bidder_id, standard_bid, time,
                        INET_NTOA(ip_address) AS ip_address_str
                    FROM {$baTable}
                    WHERE type = 'bid' AND is_accepted = 1
                    AND (is_removed IS NULL OR is_removed = 0)
                    ORDER BY ID
                    LIMIT {$batchSize} OFFSET {$offset}";

            $rows = $source->query($sql)->fetchAll();

            if (empty($rows)) {
                break;
            }

            if (!$dryRun) {
                $target->beginTransaction();
            }

            try {
                foreach ($rows as $row) {
                    $inserted = $this->processBid($row, $auctionNum, $target, $map, $dryRun, $result, $bidderCheckStmt, $insertStmt);
                    $anyInserted = $anyInserted || $inserted;
                }

                if (!$dryRun) {
                    $target->commit();
                }
            } catch (Throwable $e) {
                if (!$dryRun && $target->inTransaction()) {
                    $target->rollBack();
                }
                $this->logger->error("Chunk failed for auction {$prefix} offset {$offset}: " . $e->getMessage());
                $result->errors[] = $e->getMessage();

                return;
            }

            if (count($rows) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        if ($dryRun) {
            if ($anyInserted) {
                $this->logger->dryRun("Would compute is_winning for auction {$auctionNum} lots after insert.");
            }

            return;
        }

        if ($anyInserted) {
            $this->markWinningBids($target, $auctionNum);
        }
    }

    private function processBid(
        array $row,
        int $auctionNum,
        PDO $target,
        MigrationMap $map,
        bool $dryRun,
        StepResult $result,
        PDOStatement $bidderCheckStmt,
        PDOStatement $insertStmt
    ): bool {
        $result->processed++;
        $sourceLotId = (int) $row['inventory_id'];
        $bidderId = (int) $row['bidder_id'];

        $lotId = $dryRun ? null : $map->get($auctionNum, $sourceLotId);
        if (!$dryRun && $lotId === null) {
            $this->logger->error("Bid id={$row['id']}: no lot mapping for auction={$auctionNum} source_lot_id={$sourceLotId} — skipping.");
            $result->skipped++;

            return false;
        }

        $bidderCheckStmt->execute(['id' => $bidderId]);
        if ($bidderCheckStmt->fetchColumn() === false) {
            $this->logger->error("Bid id={$row['id']}: bidder_id={$bidderId} not found in target — skipping.");
            $result->skipped++;

            return false;
        }

        if ($dryRun) {
            $this->logger->dryRun("Would insert bid auction={$auctionNum} source_lot_id={$sourceLotId} bidder_id={$bidderId} amount={$row['standard_bid']}");
            $result->inserted++;

            return true;
        }

        $insertStmt->execute([
            'lot_id' => $lotId,
            'auction_id' => $auctionNum,
            'tenant_id' => $this->config->tenantId,
            'bidder_id' => $bidderId,
            'amount' => $row['standard_bid'],
            'ip_address' => $row['ip_address_str'],
            'created_at' => $row['time'],
        ]);
        $result->inserted++;

        return true;
    }

    /**
     * Marks the highest bid (ties broken by lowest id) per lot as
     * is_winning = true, scoped to this auction's bids.
     */
    private function markWinningBids(PDO $target, int $auctionNum): void
    {
        $sql = "UPDATE bids b
                JOIN (
                    SELECT id FROM (
                        SELECT id, ROW_NUMBER() OVER (
                            PARTITION BY lot_id ORDER BY amount DESC, id ASC
                        ) AS rn
                        FROM bids WHERE auction_id = :auction_id
                    ) ranked WHERE rn = 1
                ) winners ON winners.id = b.id
                SET b.is_winning = 1";

        $stmt = $target->prepare($sql);
        $stmt->execute(['auction_id' => $auctionNum]);
    }
}
