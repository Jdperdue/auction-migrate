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
}
