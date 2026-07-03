<?php

declare(strict_types=1);

/**
 * Creates the single René Bates tenant record. Everything downstream
 * (steps 03+) needs the resulting tenant_id copied into .env TENANT_ID.
 */
class Step01Tenant implements StepInterface
{
    private const SLUG = 'renebates';
    private const NAME = 'René Bates Auctioneers';

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

        $existing = $target->prepare('SELECT id FROM tenants WHERE slug = :slug');
        $existing->execute(['slug' => self::SLUG]);
        $existingId = $existing->fetchColumn();

        if ($existingId !== false) {
            $this->logger->warn("Tenant already exists (id={$existingId}) — skipping. Set TENANT_ID={$existingId} in .env if not already set.");
            $result->skipped = 1;

            return $result;
        }

        $email = $_ENV['TENANT_EMAIL'] ?? null;
        if ($email === null || $email === '') {
            $this->logger->warn('No TENANT_EMAIL set in .env — tenant will be created with a null email. Set it manually after migration.');
        }

        if ($dryRun) {
            $this->logger->dryRun("Would insert tenants (name=" . self::NAME . ", slug=" . self::SLUG . ", status=active)");
            $result->inserted = 1;

            return $result;
        }

        $stmt = $target->prepare(
            'INSERT INTO tenants (name, slug, email, status, created_at, updated_at)
             VALUES (:name, :slug, :email, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => self::NAME,
            'slug' => self::SLUG,
            'email' => $email ?: null,
            'status' => 'active',
        ]);

        $tenantId = (int) $target->lastInsertId();
        $result->inserted = 1;

        $this->logger->info("Created tenant id={$tenantId}. Copy this into .env as TENANT_ID before running step 03 onward.");

        return $result;
    }
}
