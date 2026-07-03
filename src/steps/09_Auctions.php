<?php

declare(strict_types=1);

/**
 * Migrates a_workspaces + a_auctions (+ a_staggered_ends) into auctions,
 * preserving workspace IDs as auction IDs (lots.auction_id and the
 * {NNN} source table prefixes reference this same ID).
 */
class Step09Auctions implements StepInterface
{
    private const STATUS_MAP = [
        'open' => 'open',
        'ending' => 'closing',
        'ended' => 'closed',
        'listed' => 'approved',
        'pending' => 'pending_approval',
        'closed' => 'closed',
    ];

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

        if ($this->config->systemUserId === null) {
            $this->logger->error('MIGRATION_SYSTEM_USER_ID is not set in .env — required for auctions.created_by.');
            $result->errors[] = 'Missing MIGRATION_SYSTEM_USER_ID';

            return $result;
        }

        $sql = "SELECT
                    w.ID AS id,
                    w.client_id AS client_id,
                    w.seller_id AS seller_id,
                    w.status AS status,
                    a.title AS title,
                    a.highlights AS highlights,
                    a.notes AS notes,
                    a.terms AS terms,
                    a.tandc AS tandc,
                    a.end_time AS end_time
                FROM a_workspaces w
                JOIN a_auctions a ON a.workspace_id = w.ID
                ORDER BY w.ID";

        $rows = $source->query($sql)->fetchAll();

        $checkStmt = $target->prepare('SELECT id FROM auctions WHERE id = :id');
        $clientCheckStmt = $target->prepare('SELECT id FROM clients WHERE id = :id');
        $sellerCheckStmt = $target->prepare('SELECT id FROM sellers WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO auctions (
                id, tenant_id, client_id, seller_id, title, description,
                preview_text, terms, opens_at, status, buyer_premium_percent,
                created_by, created_at, updated_at
             ) VALUES (
                :id, :tenant_id, :client_id, :seller_id, :title, :description,
                :preview_text, :terms, :opens_at, :status, 10.00,
                :created_by, NOW(), NOW()
             )'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $id = (int) $row['id'];

            $checkStmt->execute(['id' => $id]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->logger->warn("Auction id={$id} already exists — skipping.");
                $result->skipped++;
                continue;
            }

            $clientCheckStmt->execute(['id' => (int) $row['client_id']]);
            if ($clientCheckStmt->fetchColumn() === false) {
                $this->logger->error("Auction id={$id} references missing client_id={$row['client_id']} — skipping.");
                $result->skipped++;
                continue;
            }

            $sellerId = $row['seller_id'] !== null ? (int) $row['seller_id'] : null;
            if ($sellerId !== null) {
                $sellerCheckStmt->execute(['id' => $sellerId]);
                if ($sellerCheckStmt->fetchColumn() === false) {
                    $this->logger->warn("Auction id={$id} references missing seller_id={$sellerId} — inserting with seller_id=NULL.");
                    $sellerId = null;
                }
            }

            $status = self::STATUS_MAP[$row['status']] ?? null;
            if ($status === null) {
                $this->logger->warn("Auction id={$id} has unmapped source status \"{$row['status']}\" — defaulting to pending_approval.");
                $status = 'pending_approval';
            }

            $terms = trim(($row['terms'] ?? '') . "\n\n" . ($row['tandc'] ?? ''));
            $isHistorical = in_array($row['status'], ['ended', 'closed'], true);
            $opensAt = null;
            if (!$isHistorical && $row['end_time'] !== null && $row['end_time'] !== '0000-00-00 00:00:00') {
                $endTimestamp = strtotime($row['end_time']);
                if ($endTimestamp === false) {
                    $this->logger->warn("Auction id={$id} has unparseable end_time \"{$row['end_time']}\" — opens_at left NULL.");
                } else {
                    $opensAt = date('Y-m-d H:i:s', $endTimestamp - 14 * 86400);
                }
            }

            $params = [
                'id' => $id,
                'tenant_id' => $this->config->tenantId,
                'client_id' => (int) $row['client_id'],
                'seller_id' => $sellerId,
                'title' => $row['title'],
                'description' => $row['highlights'],
                'preview_text' => $row['notes'],
                'terms' => $terms !== '' ? $terms : null,
                'opens_at' => $opensAt,
                'status' => $status,
                'created_by' => $this->config->systemUserId,
            ];

            if ($dryRun) {
                $this->logger->dryRun("Would insert auction id={$id} title=\"{$row['title']}\" status={$status}");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute($params);
            $result->inserted++;
        }

        $this->logger->info("Auctions: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }
}
