<?php

declare(strict_types=1);

/**
 * Sets clients.commission_rate and clients.commission_cap from the
 * source's actual per-client field, c_client_data.comm_class_id1 (rate,
 * via g_commission_classes) and comm_cap. A clean FK-for-FK-plus-column
 * copy, no inference needed -- c_client_data has exactly one row per
 * client (verified: 1936/1936, 0 orphans against g_commission_classes),
 * unlike the mode-across-lots approach this step used before
 * c_client_data was found. comm_class_id2 (a secondary commission class,
 * populated for only 23/1936 clients) has no equivalent second-rate field
 * on `clients` and is left unused. commission_cap is populated for only
 * 3/1936 clients in the source; the rest are left NULL, same as before.
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

        $rows = $source->query(
            'SELECT client_id, comm_class_id1, comm_cap FROM c_client_data ORDER BY client_id'
        )->fetchAll();

        $checkStmt = $target->prepare('SELECT commission_rate FROM clients WHERE id = :id');
        $updateStmt = $target->prepare(
            'UPDATE clients SET commission_rate = :rate, commission_cap = :cap, updated_at = NOW() WHERE id = :id'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $clientId = (int) $row['client_id'];
            $classId = (int) $row['comm_class_id1'];
            $cap = $row['comm_cap'] !== null ? (float) $row['comm_cap'] : null;

            $checkStmt->execute(['id' => $clientId]);
            $existing = $checkStmt->fetchColumn();
            if ($existing !== null && $existing !== false) {
                $result->skipped++;
                continue;
            }

            if (!isset($rateByClassId[$classId])) {
                $this->logger->error("Client id={$clientId}: comm_class_id1={$classId} has no resolvable commission rate — leaving commission_rate NULL.");
                $result->skipped++;
                continue;
            }

            $ratePercent = round($rateByClassId[$classId] * 100, 2);

            if ($dryRun) {
                $this->logger->dryRun("Would set clients.id={$clientId} commission_rate={$ratePercent}" . ($cap !== null ? " commission_cap={$cap}" : ''));
                $result->inserted++;
                continue;
            }

            $updateStmt->execute(['rate' => $ratePercent, 'cap' => $cap, 'id' => $clientId]);
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
}
