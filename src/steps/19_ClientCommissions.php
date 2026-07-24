<?php

declare(strict_types=1);

/**
 * Derives a default clients.commission_rate from source data. The source has
 * no per-client commission concept — g_commission_classes (rate lookup) is
 * assigned per lot (comm_class_id on {NNN}_inventory), and the only other
 * commission table, c_indiv_commissions, is keyed by a free-text `recipient`
 * name ("David Dean SEE NOTES", "DD", ...) that doesn't join to any client.
 * This step takes each client's most-frequent comm_class_id across their own
 * historical lots and uses that class's rate as a best-effort default —
 * inferred, not authoritative. commission_cap has no source analog and is
 * left NULL. There's no status/needs_review column on `clients` to flag this
 * formally (unlike steps 14/15/17's target tables), so every write is logged
 * plainly as an inferred default an operator should verify before relying on
 * it for billing.
 */
class Step19ClientCommissions implements StepInterface
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

        $rateByClassId = $this->loadCommissionClasses($source);
        $clientIdByAuction = $this->loadAuctionClients($target);
        $classCountsByClient = $this->tallyCommClassCounts($source, $clientIdByAuction);

        $checkStmt = $target->prepare('SELECT commission_rate FROM clients WHERE id = :id');
        $updateStmt = $target->prepare(
            'UPDATE clients SET commission_rate = :rate, updated_at = NOW() WHERE id = :id'
        );

        foreach ($classCountsByClient as $clientId => $classCounts) {
            $result->processed++;

            $checkStmt->execute(['id' => $clientId]);
            $existing = $checkStmt->fetchColumn();
            if ($existing !== null && $existing !== false) {
                $result->skipped++;
                continue;
            }

            $modeClassId = $this->mostFrequent($classCounts);
            if ($modeClassId === null || !isset($rateByClassId[$modeClassId])) {
                $this->logger->warn("Client {$clientId}: no resolvable commission class across their lots — leaving commission_rate NULL.");
                $result->skipped++;
                continue;
            }

            $ratePercent = round($rateByClassId[$modeClassId] * 100, 2);

            if ($dryRun) {
                $this->logger->dryRun("Would set clients.id={$clientId} commission_rate={$ratePercent} (inferred from comm_class_id={$modeClassId}, most frequent across their lots — verify before billing).");
                $result->inserted++;
                continue;
            }

            $updateStmt->execute(['rate' => $ratePercent, 'id' => $clientId]);
            $this->logger->info("Client {$clientId}: set commission_rate={$ratePercent} — inferred default from most-frequent comm_class_id={$modeClassId}, NOT authoritative. Verify before relying on it for billing.");
            $result->inserted++;
        }

        $this->logger->info("ClientCommissions: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    /**
     * @return array<int, float> g_commission_classes.ID => com_rate
     */
    private function loadCommissionClasses(PDO $source): array
    {
        $rows = $source->query('SELECT ID AS id, com_rate FROM g_commission_classes')->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (float) $row['com_rate'];
        }

        return $map;
    }

    /**
     * @return array<int, int> auction id => client id, restricted to this tenant
     */
    private function loadAuctionClients(PDO $target): array
    {
        $stmt = $target->prepare('SELECT id, client_id FROM auctions WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => $this->config->tenantId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = (int) $row['client_id'];
        }

        return $map;
    }

    /**
     * @param array<int, int> $clientIdByAuction
     * @return array<int, array<int, int>> client id => [comm_class_id => count]
     */
    private function tallyCommClassCounts(PDO $source, array $clientIdByAuction): array
    {
        $tallies = [];
        $prefixes = Support::sourceAuctionPrefixes($source);

        foreach ($prefixes as $prefix) {
            $invTable = "{$prefix}_inventory";
            if (!Support::tableExists($source, $invTable)) {
                continue;
            }

            $auctionNum = Support::auctionNumberFromPrefix($prefix);
            $clientId = $clientIdByAuction[$auctionNum] ?? null;
            if ($clientId === null) {
                continue;
            }

            $rows = $source->query("SELECT comm_class_id FROM {$invTable}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $commClassId) {
                if ($commClassId === null) {
                    continue;
                }
                $commClassId = (int) $commClassId;
                $tallies[$clientId][$commClassId] = ($tallies[$clientId][$commClassId] ?? 0) + 1;
            }
        }

        return $tallies;
    }

    /**
     * @param array<int, int> $classCounts comm_class_id => count
     */
    private function mostFrequent(array $classCounts): ?int
    {
        if (empty($classCounts)) {
            return null;
        }

        arsort($classCounts);

        return array_key_first($classCounts);
    }
}
