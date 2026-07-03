<?php

declare(strict_types=1);

/**
 * Migrates b_bidder_deposit into bidder_deposit_accounts (one per bidder)
 * plus a bidder_deposit_transactions row for any non-zero deposit.
 */
class Step07BidderDeposits implements StepInterface
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

        $rows = $source->query(
            'SELECT bidder_id, amount_paid, payment_type, date_paid FROM b_bidder_deposit ORDER BY bidder_id'
        )->fetchAll();

        $bidderCheckStmt = $target->prepare('SELECT id FROM bidders WHERE id = :id');
        $accountCheckStmt = $target->prepare(
            'SELECT id FROM bidder_deposit_accounts WHERE bidder_id = :bidder_id AND tenant_id = :tenant_id'
        );
        $accountInsertStmt = $target->prepare(
            'INSERT INTO bidder_deposit_accounts (
                bidder_id, tenant_id, balance, credit_limit, exposure_limit,
                current_exposure, unpaid_balance, status, created_at, updated_at
             ) VALUES (
                :bidder_id, :tenant_id, :balance, 0, :exposure_limit,
                0, 0, :status, NOW(), NOW()
             )'
        );
        $transactionInsertStmt = $target->prepare(
            'INSERT INTO bidder_deposit_transactions (
                bidder_deposit_account_id, tenant_id, type, amount, reference,
                recorded_by, created_at
             ) VALUES (
                :account_id, :tenant_id, :type, :amount, :reference,
                :recorded_by, :created_at
             )'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $bidderId = (int) $row['bidder_id'];
            $amountPaid = (float) $row['amount_paid'];

            $bidderCheckStmt->execute(['id' => $bidderId]);
            if ($bidderCheckStmt->fetchColumn() === false) {
                $this->logger->error("Deposit skipped — bidder id={$bidderId} not found in target. Ensure step 05 ran successfully.");
                $result->skipped++;
                continue;
            }

            $accountCheckStmt->execute(['bidder_id' => $bidderId, 'tenant_id' => $this->config->tenantId]);
            if ($accountCheckStmt->fetchColumn() !== false) {
                $this->logger->warn("Deposit account already exists for bidder_id={$bidderId} — skipping.");
                $result->skipped++;
                continue;
            }

            if ($dryRun) {
                $this->logger->dryRun("Would insert deposit account bidder_id={$bidderId} balance={$amountPaid}" .
                    ($amountPaid != 0 ? ' + 1 deposit transaction' : ''));
                $result->inserted++;
                continue;
            }

            $accountInsertStmt->execute([
                'bidder_id' => $bidderId,
                'tenant_id' => $this->config->tenantId,
                'balance' => $amountPaid,
                'exposure_limit' => $amountPaid,
                'status' => 'active',
            ]);
            $accountId = (int) $target->lastInsertId();
            $result->inserted++;

            if ($amountPaid != 0) {
                $transactionInsertStmt->execute([
                    'account_id' => $accountId,
                    'tenant_id' => $this->config->tenantId,
                    'type' => 'deposit',
                    'amount' => $amountPaid,
                    'reference' => $row['payment_type'],
                    'recorded_by' => $this->config->systemUserId,
                    'created_at' => $row['date_paid'],
                ]);
            }
        }

        $this->logger->info("BidderDeposits: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }
}
