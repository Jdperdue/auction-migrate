<?php

declare(strict_types=1);

/**
 * Iterates the 300 possible {NNN}_inventory / {NNN}_lot_activity source
 * table sets and migrates each auction's lots into `lots` + `lot_images`.
 * Source lot IDs collide across auctions (each table set auto-increments
 * from 1), so new target IDs are assigned and recorded in
 * _migration_lot_map for step 11 (Bids) to resolve.
 *
 * `inv.list_order` maps directly to the target's `lots.sort_order`
 * (added 2026-08-12 for the staggered-lot-closing feature) — it's the
 * authoritative operator-controlled lot sequence within an auction, not
 * inferred from `lot_number` (a free-text label, not reliably sortable).
 *
 * Processed in chunks of BATCH_SIZE — not a single transaction — per
 * PROJECT.md architecture notes.
 */
class Step10Lots implements StepInterface
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

        $map = new MigrationMap($target);
        if (!$dryRun) {
            $map->ensureTable();
        }

        $bidderCheckStmt = $target->prepare('SELECT id FROM bidders WHERE id = :id');
        $auctionCheckStmt = $target->prepare('SELECT id FROM auctions WHERE id = :id');

        $prefixes = Support::sourceAuctionPrefixes($source);
        $this->logger->info(count($prefixes) . ' auction table set(s) found in source.');

        foreach ($prefixes as $prefix) {
            $this->processAuction($prefix, $source, $target, $map, $dryRun, $result, $bidderCheckStmt, $auctionCheckStmt);
        }

        $this->logger->info("Lots: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    private function processAuction(
        string $prefix,
        PDO $source,
        PDO $target,
        MigrationMap $map,
        bool $dryRun,
        StepResult $result,
        PDOStatement $bidderCheckStmt,
        PDOStatement $auctionCheckStmt
    ): void {
        $auctionNum = Support::auctionNumberFromPrefix($prefix);
        $invTable = "{$prefix}_inventory";
        $laTable = "{$prefix}_lot_activity";

        if (!Support::tableExists($source, $laTable)) {
            $this->logger->warn("{$laTable} does not exist — skipping auction {$prefix}.");

            return;
        }

        $auctionCheckStmt->execute(['id' => $auctionNum]);
        if ($auctionCheckStmt->fetchColumn() === false) {
            $this->logger->error("Auction id={$auctionNum} not found in target — skipping lots for {$prefix}. Ensure step 09 ran successfully.");

            return;
        }

        if (!$dryRun && $map->hasAuction($auctionNum)) {
            $this->logger->warn("Auction {$auctionNum} already has migrated lots — skipping to avoid duplicates.");

            return;
        }

        $wsStmt = $source->prepare('SELECT status FROM a_workspaces WHERE ID = :id');
        $wsStmt->execute(['id' => $auctionNum]);
        $workspaceStatus = $wsStmt->fetchColumn();

        $aaStmt = $source->prepare('SELECT auto_extend_time FROM a_auctions WHERE workspace_id = :id');
        $aaStmt->execute(['id' => $auctionNum]);
        $autoExtend = $aaStmt->fetchColumn();
        $softCloseMinutes = ($autoExtend !== false && $autoExtend !== null && (int) $autoExtend > 0) ? (int) $autoExtend : 5;

        $batchSize = $this->config->batchSize;
        $offset = 0;

        $insertLotStmt = $target->prepare(
            'INSERT INTO lots (
                auction_id, tenant_id, lot_number, sort_order, title, description,
                category_id, starting_bid, reserve_price, current_bid,
                current_bidder_id, bid_count, closes_at, extended_closes_at,
                status, soft_close_enabled, soft_close_minutes,
                created_at, updated_at
             ) VALUES (
                :auction_id, :tenant_id, :lot_number, :sort_order, :title, :description,
                :category_id, :starting_bid, :reserve_price, :current_bid,
                :current_bidder_id, :bid_count, :closes_at, :extended_closes_at,
                :status, 1, :soft_close_minutes,
                NOW(), NOW()
             )'
        );
        $insertImageStmt = $target->prepare(
            'INSERT INTO lot_images (lot_id, path, sort_order, is_primary, created_at, updated_at)
             VALUES (:lot_id, :path, :sort_order, :is_primary, NOW(), NOW())'
        );

        while (true) {
            $sql = "SELECT
                        inv.ID AS inv_id, inv.lot_number AS lot_number, inv.list_order AS list_order,
                        inv.description AS description,
                        inv.additional_desc AS additional_desc, inv.primary_category_id AS category_id,
                        inv.min_bid AS min_bid, inv.item_reserve AS item_reserve,
                        inv.photo_string AS photo_string, inv.premium AS premium,
                        la.end_time AS end_time, la.current_price AS current_price,
                        la.current_winner_id AS current_winner_id, la.bid_count AS bid_count,
                        la.is_extended AS is_extended, la.is_halted AS is_halted
                    FROM {$invTable} inv
                    JOIN {$laTable} la ON la.inventory_id = inv.ID
                    ORDER BY inv.ID
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
                    $this->processLot(
                        $row,
                        $auctionNum,
                        $workspaceStatus,
                        $softCloseMinutes,
                        $target,
                        $map,
                        $dryRun,
                        $result,
                        $bidderCheckStmt,
                        $insertLotStmt,
                        $insertImageStmt
                    );
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
    }

    private function processLot(
        array $row,
        int $auctionNum,
        $workspaceStatus,
        int $softCloseMinutes,
        PDO $target,
        MigrationMap $map,
        bool $dryRun,
        StepResult $result,
        PDOStatement $bidderCheckStmt,
        PDOStatement $insertLotStmt,
        PDOStatement $insertImageStmt
    ): void {
        $result->processed++;
        $sourceLotId = (int) $row['inv_id'];

        $sourceCategoryId = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryId = $sourceCategoryId !== null ? $map->getCategory($sourceCategoryId) : null;
        if ($sourceCategoryId !== null && $categoryId === null) {
            $this->logger->warn("Auction {$auctionNum} lot {$sourceLotId}: source category_id={$sourceCategoryId} has no target mapping — setting NULL.");
        }

        $currentBidderId = $row['current_winner_id'] !== null ? (int) $row['current_winner_id'] : null;
        if ($currentBidderId !== null) {
            $bidderCheckStmt->execute(['id' => $currentBidderId]);
            if ($bidderCheckStmt->fetchColumn() === false) {
                $this->logger->warn("Auction {$auctionNum} lot {$sourceLotId}: current_winner_id={$currentBidderId} not found in target — setting NULL.");
                $currentBidderId = null;
            }
        }

        $reserve = ((float) $row['item_reserve']) === 0.0 ? null : $row['item_reserve'];
        $isExtended = ((int) $row['is_extended']) > 0;
        $extendedClosesAt = $isExtended ? $row['end_time'] : null;

        $premium = $row['premium'];
        if ($premium !== null && (float) $premium !== 0.0 && (float) $premium !== 10.0) {
            $this->logger->info("Auction {$auctionNum} lot {$sourceLotId}: source premium override ({$premium}) has no target field — recorded in log only.");
        }

        if ((int) $row['is_halted'] === 1) {
            $status = 'pending';
        } elseif ($currentBidderId !== null) {
            $status = 'closed';
        } elseif ($workspaceStatus === 'closed') {
            $status = 'closed';
        } else {
            $status = 'open';
        }

        $title = (string) $row['description'];
        $description = $row['additional_desc'];
        if (mb_strlen($title) > 255) {
            $this->logger->warn("Auction {$auctionNum} lot {$sourceLotId}: title exceeds 255 chars — truncating and preserving full text in description.");
            $description = trim($title . "\n\n" . ($description ?? ''));
            $title = mb_substr($title, 0, 255);
        }

        $images = array_values(array_filter(preg_split('/\s+/', trim((string) $row['photo_string'])) ?: []));

        if ($dryRun) {
            $this->logger->dryRun("Would insert lot auction={$auctionNum} source_lot_id={$sourceLotId} status={$status} images=" . count($images));
            $result->inserted++;

            return;
        }

        $insertLotStmt->execute([
            'auction_id' => $auctionNum,
            'tenant_id' => $this->config->tenantId,
            'lot_number' => $row['lot_number'],
            'sort_order' => (int) $row['list_order'],
            'title' => $title,
            'description' => $description,
            'category_id' => $categoryId,
            'starting_bid' => $row['min_bid'],
            'reserve_price' => $reserve,
            'current_bid' => $row['current_price'],
            'current_bidder_id' => $currentBidderId,
            'bid_count' => $row['bid_count'],
            'closes_at' => $row['end_time'],
            'extended_closes_at' => $extendedClosesAt,
            'status' => $status,
            'soft_close_minutes' => $softCloseMinutes,
        ]);

        $targetLotId = (int) $target->lastInsertId();
        $map->set($auctionNum, $sourceLotId, $targetLotId);
        $result->inserted++;

        foreach ($images as $i => $filename) {
            $insertImageStmt->execute([
                'lot_id' => $targetLotId,
                'path' => $filename,
                'sort_order' => $i,
                'is_primary' => $i === 0 ? 1 : 0,
            ]);
        }
    }
}
