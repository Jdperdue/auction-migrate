<?php

declare(strict_types=1);

/**
 * Manages the _migration_lot_map table in the target DB, which resolves
 * source (auction, lot) pairs to newly assigned target lot IDs. Needed
 * because lot IDs collide across the 300 source auction table sets and
 * cannot be preserved.
 */
class MigrationMap
{
    private PDO $target;

    public function __construct(PDO $target)
    {
        $this->target = $target;
    }

    public function ensureTable(): void
    {
        $this->target->exec(
            'CREATE TABLE IF NOT EXISTS _migration_lot_map (
                source_auction INT NOT NULL,
                source_lot_id  INT NOT NULL,
                target_lot_id  BIGINT NOT NULL,
                PRIMARY KEY (source_auction, source_lot_id)
            )'
        );
    }

    public function set(int $auction, int $sourceLotId, int $targetLotId): void
    {
        $stmt = $this->target->prepare(
            'INSERT INTO _migration_lot_map (source_auction, source_lot_id, target_lot_id)
             VALUES (:auction, :source_lot_id, :target_lot_id)
             ON DUPLICATE KEY UPDATE target_lot_id = VALUES(target_lot_id)'
        );
        $stmt->execute([
            'auction' => $auction,
            'source_lot_id' => $sourceLotId,
            'target_lot_id' => $targetLotId,
        ]);
    }

    public function get(int $auction, int $sourceLotId): ?int
    {
        $stmt = $this->target->prepare(
            'SELECT target_lot_id FROM _migration_lot_map
             WHERE source_auction = :auction AND source_lot_id = :source_lot_id'
        );
        $stmt->execute([
            'auction' => $auction,
            'source_lot_id' => $sourceLotId,
        ]);

        $result = $stmt->fetchColumn();

        return $result === false ? null : (int) $result;
    }

    public function hasAuction(int $auction): bool
    {
        $stmt = $this->target->prepare(
            'SELECT 1 FROM _migration_lot_map WHERE source_auction = :auction LIMIT 1'
        );
        $stmt->execute(['auction' => $auction]);

        return $stmt->fetchColumn() !== false;
    }

    public function drop(): void
    {
        $this->target->exec('DROP TABLE IF EXISTS _migration_lot_map');
    }

    /**
     * _migration_category_map resolves source g_categories.ID to the
     * tenant-scoped target categories.id. Unlike the lot map, this is never
     * dropped by reset.php — categories persist across resets (they're
     * seeded per-tenant from a category_template, not migrated content), so
     * the map must survive too for 02_Categories to stay idempotent.
     */
    public function ensureCategoryMapTable(): void
    {
        $this->target->exec(
            'CREATE TABLE IF NOT EXISTS _migration_category_map (
                source_category_id INT NOT NULL PRIMARY KEY,
                target_category_id BIGINT NOT NULL
            )'
        );
    }

    public function setCategory(int $sourceCategoryId, int $targetCategoryId): void
    {
        $stmt = $this->target->prepare(
            'INSERT INTO _migration_category_map (source_category_id, target_category_id)
             VALUES (:source_category_id, :target_category_id)
             ON DUPLICATE KEY UPDATE target_category_id = VALUES(target_category_id)'
        );
        $stmt->execute([
            'source_category_id' => $sourceCategoryId,
            'target_category_id' => $targetCategoryId,
        ]);
    }

    public function getCategory(int $sourceCategoryId): ?int
    {
        $stmt = $this->target->prepare(
            'SELECT target_category_id FROM _migration_category_map WHERE source_category_id = :source_category_id'
        );
        $stmt->execute(['source_category_id' => $sourceCategoryId]);

        $result = $stmt->fetchColumn();

        return $result === false ? null : (int) $result;
    }
}
