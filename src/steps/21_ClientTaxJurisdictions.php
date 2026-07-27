<?php

declare(strict_types=1);

/**
 * Sets clients.tax_jurisdiction_id from the source's actual per-client
 * field, c_client_data.tax_class_id -> g_tax_classes, matched by name to
 * the tax_jurisdictions rows step 17 already seeded. A clean FK-for-FK
 * swap, no inference needed -- c_client_data has exactly one row per
 * client (verified: 1936/1936, 0 orphans against g_tax_classes), unlike
 * the mode-across-lots approach this step used before c_client_data was
 * found. Runs after step 17 (tax_jurisdictions must already be seeded);
 * no longer depends on lots/auctions at all.
 */
class Step21ClientTaxJurisdictions implements StepInterface
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

        $classIdToJurisdictionId = $this->resolveJurisdictionMap($source, $target);

        $rows = $source->query('SELECT client_id, tax_class_id FROM c_client_data ORDER BY client_id')->fetchAll();

        $updateStmt = $target->prepare(
            'UPDATE clients SET tax_jurisdiction_id = :jurisdiction_id, updated_at = NOW()
             WHERE id = :id AND tax_jurisdiction_id IS NULL'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $clientId = (int) $row['client_id'];
            $taxClassId = (int) $row['tax_class_id'];

            if (!isset($classIdToJurisdictionId[$taxClassId])) {
                $this->logger->error("Client id={$clientId}: tax_class_id={$taxClassId} has no resolved jurisdiction — skipping.");
                $result->skipped++;
                continue;
            }

            $jurisdictionId = $classIdToJurisdictionId[$taxClassId];

            if ($dryRun) {
                $this->logger->dryRun("Would set clients.id={$clientId} tax_jurisdiction_id={$jurisdictionId}");
                $result->inserted++;
                continue;
            }

            $updateStmt->execute(['jurisdiction_id' => $jurisdictionId, 'id' => $clientId]);
            if ($updateStmt->rowCount() === 0) {
                $result->skipped++;
                continue;
            }

            $result->inserted++;
        }

        $this->logger->info("ClientTaxJurisdictions: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    /**
     * @return array<int, int> source g_tax_classes.ID => target tax_jurisdictions.id
     */
    private function resolveJurisdictionMap(PDO $source, PDO $target): array
    {
        $sourceClasses = $source->query('SELECT ID AS id, tax_description AS name FROM g_tax_classes')->fetchAll();

        $findStmt = $target->prepare('SELECT id FROM tax_jurisdictions WHERE tenant_id = :tenant_id AND name = :name');

        $map = [];
        foreach ($sourceClasses as $row) {
            $findStmt->execute(['tenant_id' => $this->config->tenantId, 'name' => $row['name']]);
            $jurisdictionId = $findStmt->fetchColumn();
            if ($jurisdictionId !== false) {
                $map[(int) $row['id']] = (int) $jurisdictionId;
            }
        }

        return $map;
    }
}
