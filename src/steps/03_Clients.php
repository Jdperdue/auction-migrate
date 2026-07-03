<?php

declare(strict_types=1);

/**
 * Migrates c_clients + c_client_contact (+ lookup joins) into clients,
 * preserving source IDs.
 */
class Step03Clients implements StepInterface
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
                    c.ID AS id,
                    cc.company AS company,
                    cc.full_name AS full_name,
                    ge.address AS email,
                    work.number AS phone,
                    cc.m_address1 AS address,
                    gz.zip_code AS zip,
                    gc.city_name AS city,
                    gs.state_name AS state
                FROM c_clients c
                JOIN c_client_contact cc ON cc.client_id = c.ID
                JOIN g_emails ge ON ge.ID = cc.email_id
                JOIN g_phones work ON work.ID = cc.work_phone_id
                JOIN g_zip_codes gz ON gz.ID = cc.m_zip_id
                JOIN g_cities gc ON gc.ID = gz.city_id
                JOIN g_states gs ON gs.ID = gc.state_id
                ORDER BY c.ID";

        $rows = $source->query($sql)->fetchAll();

        $usedSlugs = [];
        $slugStmt = $target->prepare('SELECT slug FROM clients WHERE tenant_id = :tenant_id');
        $slugStmt->execute(['tenant_id' => $this->config->tenantId]);
        foreach ($slugStmt->fetchAll(PDO::FETCH_COLUMN) as $existingSlug) {
            $usedSlugs[$existingSlug] = true;
        }

        $checkStmt = $target->prepare('SELECT id FROM clients WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO clients (
                id, tenant_id, name, slug, contact_email, contact_name,
                contact_phone, address, city, state, zip, is_self_serve, status,
                created_at, updated_at
             ) VALUES (
                :id, :tenant_id, :name, :slug, :contact_email, :contact_name,
                :phone, :address, :city, :state, :zip, 0, :status,
                NOW(), NOW()
             )'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $id = (int) $row['id'];

            $checkStmt->execute(['id' => $id]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->logger->warn("Client id={$id} already exists — skipping.");
                $result->skipped++;
                continue;
            }

            $slug = Support::slugify($row['company']);
            if (isset($usedSlugs[$slug])) {
                $this->logger->warn("Slug collision for client id={$id} company=\"{$row['company']}\" — appending id to slug.");
                $slug .= '-' . $id;
            }
            $usedSlugs[$slug] = true;

            $params = [
                'id' => $id,
                'tenant_id' => $this->config->tenantId,
                'name' => $row['company'],
                'slug' => $slug,
                'contact_email' => $row['email'],
                'contact_name' => $row['full_name'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'city' => $row['city'],
                'state' => $row['state'],
                'zip' => $row['zip'],
                'status' => 'active',
            ];

            if ($dryRun) {
                $this->logger->dryRun("Would insert client id={$id} name=\"{$row['company']}\"");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute($params);
            $result->inserted++;
        }

        $this->logger->info("Clients: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }
}
