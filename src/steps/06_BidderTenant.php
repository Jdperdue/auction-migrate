<?php

declare(strict_types=1);

/**
 * Creates the bidder_tenant pivot row for every migrated bidder.
 */
class Step06BidderTenant implements StepInterface
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

        $sql = "SELECT bb.ID AS id, bb.created AS created, bs.status AS status
                FROM b_bidders bb
                LEFT JOIN b_bidder_status bs ON bs.bidder_id = bb.ID
                ORDER BY bb.ID";

        $rows = $source->query($sql)->fetchAll();

        $bidderCheckStmt = $target->prepare('SELECT id FROM bidders WHERE id = :id');
        $checkStmt = $target->prepare(
            'SELECT id FROM bidder_tenant WHERE bidder_id = :bidder_id AND tenant_id = :tenant_id'
        );
        $insertStmt = $target->prepare(
            'INSERT INTO bidder_tenant (
                bidder_id, tenant_id, status, terms_accepted_at, approved_at,
                created_at, updated_at
             ) VALUES (
                :bidder_id, :tenant_id, :status, :terms_accepted_at, :approved_at,
                NOW(), NOW()
             )'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $bidderId = (int) $row['id'];

            $bidderCheckStmt->execute(['id' => $bidderId]);
            if ($bidderCheckStmt->fetchColumn() === false) {
                $this->logger->error("bidder_tenant skipped — bidder id={$bidderId} not found in target. Ensure step 05 ran successfully.");
                $result->skipped++;
                continue;
            }

            $checkStmt->execute(['bidder_id' => $bidderId, 'tenant_id' => $this->config->tenantId]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->logger->warn("bidder_tenant already exists for bidder_id={$bidderId} — skipping.");
                $result->skipped++;
                continue;
            }

            $status = match ($row['status']) {
                'active' => 'active',
                'inactive' => 'suspended',
                'limited' => 'active',
                'banned' => 'suspended',
                default => 'pending',
            };

            $params = [
                'bidder_id' => $bidderId,
                'tenant_id' => $this->config->tenantId,
                'status' => $status,
                'terms_accepted_at' => $row['created'],
                'approved_at' => $row['created'],
            ];

            if ($dryRun) {
                $this->logger->dryRun("Would insert bidder_tenant bidder_id={$bidderId} status={$status}");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute($params);
            $result->inserted++;
        }

        $this->logger->info("BidderTenant: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }
}
