<?php

declare(strict_types=1);

/**
 * Migrates b_bidder_notes + g_notes into bidder_notes. Also folds
 * b_bidder_status.details (the free-text reason a bidder was banned or
 * limited) into a flagged bidder_notes row — see
 * docs/migration-gap-analysis.md "Bidder Status Details".
 */
class Step08BidderNotes implements StepInterface
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

        $existingCheckStmt = $target->prepare('SELECT COUNT(*) FROM bidder_notes WHERE tenant_id = :tenant_id');
        $existingCheckStmt->execute(['tenant_id' => $this->config->tenantId]);
        if ((int) $existingCheckStmt->fetchColumn() > 0) {
            $this->logger->warn('bidder_notes already has rows for this tenant — skipping to avoid duplicates. Truncate/delete manually first if you need to re-run this step.');

            return $result;
        }

        $bidderCheckStmt = $target->prepare('SELECT id FROM bidders WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO bidder_notes (
                bidder_id, tenant_id, author_id, note, is_flagged, created_at, updated_at
             ) VALUES (
                :bidder_id, :tenant_id, :author_id, :note, :is_flagged, :created_at, NOW()
             )'
        );

        $this->migrateNotes($source, $target, $bidderCheckStmt, $insertStmt, $result, $dryRun);
        $this->migrateStatusDetails($source, $target, $bidderCheckStmt, $insertStmt, $result, $dryRun);

        $this->logger->info("BidderNotes: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    private function migrateNotes(PDO $source, PDO $target, PDOStatement $bidderCheckStmt, PDOStatement $insertStmt, StepResult $result, bool $dryRun): void
    {
        $sql = "SELECT bn.bidder_id AS bidder_id, gn.note AS note, gn.created AS created, gn.creator AS creator
                FROM b_bidder_notes bn
                JOIN g_notes gn ON gn.ID = bn.note_id
                ORDER BY bn.bidder_id, gn.created";

        $rows = $source->query($sql)->fetchAll();

        foreach ($rows as $row) {
            $result->processed++;
            $bidderId = (int) $row['bidder_id'];

            $bidderCheckStmt->execute(['id' => $bidderId]);
            if ($bidderCheckStmt->fetchColumn() === false) {
                $this->logger->error("Note skipped — bidder id={$bidderId} not found in target.");
                $result->skipped++;
                continue;
            }

            $authorId = in_array($row['creator'], ['admin', 'auction'], true) ? $this->config->systemUserId : null;

            if ($dryRun) {
                $this->logger->dryRun("Would insert bidder_note bidder_id={$bidderId}");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute([
                'bidder_id' => $bidderId,
                'tenant_id' => $this->config->tenantId,
                'author_id' => $authorId,
                'note' => $row['note'],
                'is_flagged' => 0,
                'created_at' => $row['created'],
            ]);
            $result->inserted++;
        }
    }

    private function migrateStatusDetails(PDO $source, PDO $target, PDOStatement $bidderCheckStmt, PDOStatement $insertStmt, StepResult $result, bool $dryRun): void
    {
        $sql = "SELECT bidder_id, status, details
                FROM b_bidder_status
                WHERE status IN ('banned', 'limited') AND details IS NOT NULL AND details != ''
                ORDER BY bidder_id";

        $rows = $source->query($sql)->fetchAll();

        foreach ($rows as $row) {
            $result->processed++;
            $bidderId = (int) $row['bidder_id'];

            $bidderCheckStmt->execute(['id' => $bidderId]);
            if ($bidderCheckStmt->fetchColumn() === false) {
                $this->logger->error("Status-detail note skipped — bidder id={$bidderId} not found in target.");
                $result->skipped++;
                continue;
            }

            $note = sprintf('[%s] %s', strtoupper($row['status']), $row['details']);

            if ($dryRun) {
                $this->logger->dryRun("Would insert flagged bidder_note bidder_id={$bidderId} (from b_bidder_status.details)");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute([
                'bidder_id' => $bidderId,
                'tenant_id' => $this->config->tenantId,
                'author_id' => $this->config->systemUserId,
                'note' => $note,
                'is_flagged' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $result->inserted++;
        }
    }
}
