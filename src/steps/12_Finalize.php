<?php

declare(strict_types=1);

/**
 * Sets lots.winning_bid_id from the is_winning bids marked by step 11,
 * then runs the count/spot-check queries from
 * docs/migration-renebates.md "Post-Migration Verification Queries".
 * Does not drop _migration_lot_map — per PROJECT.md, that happens
 * manually once the operator is satisfied with verification results.
 */
class Step12Finalize implements StepInterface
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
        $result->processed = 1;

        if ($dryRun) {
            $this->logger->dryRun('Would UPDATE lots.winning_bid_id from bids WHERE is_winning = 1.');
        } else {
            $stmt = $target->exec(
                'UPDATE lots l
                 JOIN bids b ON b.lot_id = l.id AND b.is_winning = 1
                 SET l.winning_bid_id = b.id
                 WHERE l.winning_bid_id IS NULL'
            );
            $result->inserted = (int) $stmt;
            $this->logger->info("Set winning_bid_id on {$result->inserted} lot(s).");
        }

        $this->verifyCounts($source, $target);
        $this->verifyWinningBidsSet($target);
        $this->verifyDepositBalances($target);

        return $result;
    }

    private function verifyCounts(PDO $source, PDO $target): void
    {
        $targetClients = (int) $target->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $sourceClients = (int) $source->query('SELECT COUNT(*) FROM c_clients')->fetchColumn();
        $this->compare('clients', $sourceClients, $targetClients);

        $targetBidders = (int) $target->query('SELECT COUNT(*) FROM bidders')->fetchColumn();
        $sourceBidders = (int) $source->query('SELECT COUNT(*) FROM b_bidders')->fetchColumn();
        $this->compare('bidders', $sourceBidders, $targetBidders);

        $targetAuctions = (int) $target->query('SELECT COUNT(*) FROM auctions')->fetchColumn();
        $sourceAuctions = (int) $source->query('SELECT COUNT(*) FROM a_workspaces')->fetchColumn();
        $this->compare('auctions', $sourceAuctions, $targetAuctions);

        $prefixes = Support::sourceAuctionPrefixes($source);

        $sourceLots = 0;
        $sourceBids = 0;
        foreach ($prefixes as $prefix) {
            $invTable = "{$prefix}_inventory";
            $baTable = "{$prefix}_bidder_activity";

            $sourceLots += (int) $source->query("SELECT COUNT(*) FROM {$invTable}")->fetchColumn();

            if (Support::tableExists($source, $baTable)) {
                $sourceBids += (int) $source->query(
                    "SELECT COUNT(*) FROM {$baTable} WHERE type = 'bid' AND is_accepted = 1
                     AND (is_removed IS NULL OR is_removed = 0)"
                )->fetchColumn();
            }
        }

        $targetLots = (int) $target->query('SELECT COUNT(*) FROM lots')->fetchColumn();
        $this->compare('lots', $sourceLots, $targetLots);

        $targetBids = (int) $target->query('SELECT COUNT(*) FROM bids')->fetchColumn();
        $this->compare('bids', $sourceBids, $targetBids);
    }

    private function compare(string $label, int $sourceCount, int $targetCount): void
    {
        if ($sourceCount === $targetCount) {
            $this->logger->info("Count check [{$label}]: source={$sourceCount} target={$targetCount} MATCH.");
        } else {
            $this->logger->warn("Count check [{$label}]: source={$sourceCount} target={$targetCount} MISMATCH (expected if some rows were skipped for missing FKs — review the log).");
        }
    }

    private function verifyWinningBidsSet(PDO $target): void
    {
        $count = (int) $target->query(
            "SELECT COUNT(*) FROM lots WHERE status = 'closed' AND winning_bid_id IS NULL"
        )->fetchColumn();

        if ($count === 0) {
            $this->logger->info('Winning bid check: 0 closed lots missing winning_bid_id. OK.');
        } else {
            $this->logger->warn("Winning bid check: {$count} closed lot(s) missing winning_bid_id — review manually.");
        }
    }

    private function verifyDepositBalances(PDO $target): void
    {
        $count = (int) $target->query(
            "SELECT COUNT(*) FROM bidder_deposit_accounts
             WHERE balance != (
                SELECT COALESCE(SUM(amount), 0) FROM bidder_deposit_transactions
                WHERE bidder_deposit_account_id = bidder_deposit_accounts.id AND type = 'deposit'
             )"
        )->fetchColumn();

        if ($count === 0) {
            $this->logger->info('Deposit balance check: 0 mismatches. OK.');
        } else {
            $this->logger->warn("Deposit balance check: {$count} account(s) with balance != sum(deposit transactions) — review manually.");
        }
    }
}
