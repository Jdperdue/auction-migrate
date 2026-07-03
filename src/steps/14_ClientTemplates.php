<?php

declare(strict_types=1);

/**
 * Seeds client_terms_templates from each client's most recent source
 * auction that has a non-empty terms blob. The source has no clean
 * per-client "current terms" table — a_auctions.terms is a single HTML
 * blob per auction with payment/removal/title-transfer instructions
 * concatenated under "<u>SECTION:</u>" markers — so this step infers a
 * one-time seed per client by picking their most recent auction and
 * splitting that blob. Rows are inserted with status=needs_review; this is
 * a seeding function only, not a source of truth — operators must proof
 * each client's template before it's used on new auctions.
 */
class Step14ClientTemplates implements StepInterface
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

        $clientIds = $target->prepare('SELECT id FROM clients WHERE tenant_id = :tenant_id ORDER BY id');
        $clientIds->execute(['tenant_id' => $this->config->tenantId]);
        $clientIds = $clientIds->fetchAll(PDO::FETCH_COLUMN);

        $existsStmt = $target->prepare('SELECT 1 FROM client_terms_templates WHERE client_id = :client_id');
        $auctionCheckStmt = $target->prepare('SELECT id FROM auctions WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO client_terms_templates (
                client_id, source_auction_id, payment_terms, removal_terms,
                title_transfer_instructions, status, created_at, updated_at
             ) VALUES (
                :client_id, :source_auction_id, :payment_terms, :removal_terms,
                :title_transfer_instructions, \'needs_review\', NOW(), NOW()
             )'
        );

        $sourceStmt = $source->prepare(
            'SELECT w.ID AS workspace_id, a.terms AS terms
             FROM a_workspaces w
             JOIN a_auctions a ON a.workspace_id = w.ID
             WHERE w.client_id = :client_id AND a.terms IS NOT NULL AND a.terms != \'\'
             ORDER BY w.ID DESC
             LIMIT 1'
        );

        foreach ($clientIds as $clientId) {
            $clientId = (int) $clientId;
            $result->processed++;

            $existsStmt->execute(['client_id' => $clientId]);
            if ($existsStmt->fetchColumn() !== false) {
                $result->skipped++;
                continue;
            }

            $sourceStmt->execute(['client_id' => $clientId]);
            $row = $sourceStmt->fetch();

            if ($row === false) {
                $this->logger->warn("Client {$clientId}: no source auction with a non-empty terms blob — skipping.");
                $result->skipped++;
                continue;
            }

            $sections = Support::splitMarkedSections((string) $row['terms']);
            $paymentTerms = $this->findSection($sections, 'PAYMENT');
            $removalTerms = $this->findSection($sections, 'REMOVAL');
            $titleTransfer = $this->findSection($sections, 'PAPERWORK') ?? $this->findSection($sections, 'TITLE');

            if ($paymentTerms === null && $removalTerms === null && $titleTransfer === null) {
                $this->logger->warn("Client {$clientId}: source auction {$row['workspace_id']} terms blob had no recognizable sections — skipping.");
                $result->skipped++;
                continue;
            }

            $workspaceId = (int) $row['workspace_id'];
            $auctionCheckStmt->execute(['id' => $workspaceId]);
            $sourceAuctionId = $auctionCheckStmt->fetchColumn() !== false ? $workspaceId : null;

            if ($dryRun) {
                $this->logger->dryRun(
                    "Would insert client_terms_templates client_id={$clientId} source_auction_id="
                    . ($sourceAuctionId ?? 'NULL')
                    . ' payment=' . ($paymentTerms !== null ? 'yes' : 'no')
                    . ' removal=' . ($removalTerms !== null ? 'yes' : 'no')
                    . ' title_transfer=' . ($titleTransfer !== null ? 'yes' : 'no')
                );
                $result->inserted++;
                continue;
            }

            $insertStmt->execute([
                'client_id' => $clientId,
                'source_auction_id' => $sourceAuctionId,
                'payment_terms' => $paymentTerms,
                'removal_terms' => $removalTerms,
                'title_transfer_instructions' => $titleTransfer,
            ]);
            $result->inserted++;
        }

        $this->logger->info("ClientTemplates: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    /**
     * @param array<int, array{label: string, content: string}> $sections
     */
    private function findSection(array $sections, string $keyword): ?string
    {
        foreach ($sections as $section) {
            if (stripos($section['label'], $keyword) !== false) {
                return $section['content'];
            }
        }

        return null;
    }
}
