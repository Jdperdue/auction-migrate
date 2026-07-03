<?php

declare(strict_types=1);

/**
 * Migrates c_sellers + c_seller_contact (+ lookup joins) into sellers,
 * preserving source IDs.
 */
class Step04Sellers implements StepInterface
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

        $sql = "SELECT
                    s.ID AS id,
                    s.client_id AS client_id,
                    sc.description AS description,
                    sc.full_name AS full_name,
                    ge.address AS email,
                    work.number AS phone
                FROM c_sellers s
                JOIN c_seller_contact sc ON sc.seller_id = s.ID
                JOIN g_emails ge ON ge.ID = sc.email_id
                JOIN g_phones work ON work.ID = sc.work_phone_id
                ORDER BY s.ID";

        $rows = $source->query($sql)->fetchAll();

        $usedSlugs = [];
        $slugStmt = $target->query('SELECT client_id, slug FROM sellers');
        foreach ($slugStmt->fetchAll() as $existing) {
            $usedSlugs[$existing['client_id'] . ':' . $existing['slug']] = true;
        }

        $checkStmt = $target->prepare('SELECT id FROM sellers WHERE id = :id');
        $clientCheckStmt = $target->prepare('SELECT id FROM clients WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO sellers (
                id, client_id, tenant_id, name, slug, contact_email,
                contact_name, contact_phone, status, created_at, updated_at
             ) VALUES (
                :id, :client_id, :tenant_id, :name, :slug, :contact_email,
                :contact_name, :contact_phone, :status, NOW(), NOW()
             )'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $id = (int) $row['id'];
            $clientId = (int) $row['client_id'];

            $checkStmt->execute(['id' => $id]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->logger->warn("Seller id={$id} already exists — skipping.");
                $result->skipped++;
                continue;
            }

            $clientCheckStmt->execute(['id' => $clientId]);
            if ($clientCheckStmt->fetchColumn() === false) {
                $this->logger->error("Seller id={$id} references missing client_id={$clientId} — skipping. Ensure step 03 ran successfully.");
                $result->skipped++;
                continue;
            }

            $slug = Support::slugify($row['description']);
            if (isset($usedSlugs[$clientId . ':' . $slug])) {
                $this->logger->warn("Slug collision for seller id={$id} client_id={$clientId} name=\"{$row['description']}\" — appending id to slug.");
                $slug .= '-' . $id;
            }
            $usedSlugs[$clientId . ':' . $slug] = true;

            $params = [
                'id' => $id,
                'client_id' => $clientId,
                'tenant_id' => $this->config->tenantId,
                'name' => $row['description'],
                'slug' => $slug,
                'contact_email' => $row['email'],
                'contact_name' => $row['full_name'],
                'contact_phone' => $row['phone'],
                'status' => 'active',
            ];

            if ($dryRun) {
                $this->logger->dryRun("Would insert seller id={$id} name=\"{$row['description']}\" client_id={$clientId}");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute($params);
            $result->inserted++;
        }

        $this->logger->info("Sellers: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }
}
