<?php

declare(strict_types=1);

/**
 * Migrates b_bidders + b_bidder_contact + b_bidder_status (+ lookup joins)
 * into bidders, preserving source IDs (bids reference bidder IDs directly).
 *
 * Password migration: source passwords are MD5, incompatible with
 * Laravel's bcrypt. Per PROJECT.md "Password Migration Decision" (Option
 * A), every migrated bidder gets an invalid bcrypt placeholder and
 * `requires_password_reset = true`. NOTE: as of this writing that column
 * does not yet exist on the target `bidders` table — it must be added via
 * a Laravel migration in /var/www/auction/ before this step can run live.
 */
class Step05Bidders implements StepInterface
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

        if (!Support::tableExists($target, 'bidders')) {
            $this->logger->error('Target table `bidders` not found.');
            $result->errors[] = 'Missing target table bidders';

            return $result;
        }

        if (!$this->hasRequiresPasswordResetColumn($target)) {
            $this->logger->warn(
                'bidders.requires_password_reset column does not exist on the target DB yet. ' .
                'This must be added via a Laravel migration in /var/www/auction/ before a LIVE run ' .
                '(dry-run will proceed since no writes occur).'
            );
        }

        $sql = "SELECT
                    bb.ID AS id,
                    bb.created AS created,
                    bc.full_name AS full_name,
                    ge.address AS email,
                    work.number AS phone,
                    bc.m_address1 AS address,
                    gz.zip_code AS zip,
                    gc.city_name AS city,
                    gs.state_name AS state,
                    bs.status AS status
                FROM b_bidders bb
                JOIN b_bidder_contact bc ON bc.bidder_id = bb.ID
                JOIN g_emails ge ON ge.ID = bc.email_id
                JOIN g_phones work ON work.ID = bc.work_phone_id
                JOIN g_zip_codes gz ON gz.ID = bc.m_zip_id
                JOIN g_cities gc ON gc.ID = gz.city_id
                JOIN g_states gs ON gs.ID = gc.state_id
                LEFT JOIN b_bidder_status bs ON bs.bidder_id = bb.ID
                ORDER BY bb.ID";

        $rows = $source->query($sql)->fetchAll();

        $checkStmt = $target->prepare('SELECT id FROM bidders WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO bidders (
                id, tenant_id, first_name, last_name, email, phone, address,
                city, state, zip, password, requires_password_reset, status,
                email_verified_at, terms_accepted_at, created_at, updated_at
             ) VALUES (
                :id, :tenant_id, :first_name, :last_name, :email, :phone, :address,
                :city, :state, :zip, :password, 1, :status,
                :email_verified_at, :terms_accepted_at, :created_at, NOW()
             )'
        );

        foreach ($rows as $row) {
            $result->processed++;
            $id = (int) $row['id'];

            $checkStmt->execute(['id' => $id]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->logger->warn("Bidder id={$id} already exists — skipping.");
                $result->skipped++;
                continue;
            }

            [$firstName, $lastName] = Support::splitName($row['full_name']);
            $status = $this->mapStatus($row['status'], $id);

            $params = [
                'id' => $id,
                'tenant_id' => $this->config->tenantId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $row['email'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'city' => $row['city'],
                'state' => $row['state'],
                'zip' => $row['zip'],
                'password' => $this->invalidBcryptPlaceholder(),
                'status' => $status,
                'email_verified_at' => $row['created'],
                'terms_accepted_at' => $row['created'],
                'created_at' => $row['created'],
            ];

            if ($dryRun) {
                $this->logger->dryRun("Would insert bidder id={$id} email={$row['email']} status={$status}");
                $result->inserted++;
                continue;
            }

            $insertStmt->execute($params);
            $result->inserted++;
        }

        $this->logger->info("Bidders: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    private function mapStatus(?string $sourceStatus, int $bidderId): string
    {
        return match ($sourceStatus) {
            'active' => 'active',
            'inactive' => 'inactive',
            'limited' => 'active',
            'banned' => 'suspended',
            default => (function () use ($bidderId) {
                $this->logger->warn("Bidder id={$bidderId} has no b_bidder_status row — defaulting status to active.");

                return 'active';
            })(),
        };
    }

    private function invalidBcryptPlaceholder(): string
    {
        // Deliberately not a valid bcrypt hash — password_verify() always
        // returns false against it, so no plaintext can ever authenticate.
        return '!MIGRATED!' . bin2hex(random_bytes(20));
    }

    private function hasRequiresPasswordResetColumn(PDO $target): bool
    {
        $stmt = $target->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => 'bidders', 'column' => 'requires_password_reset']);

        return (int) $stmt->fetchColumn() > 0;
    }
}
