<?php

declare(strict_types=1);

/**
 * Resolves g_categories WHERE cat_type = 'lot' against the tenant's
 * categories, recording source ID -> target ID in
 * _migration_category_map for step 10 (Lots) to resolve.
 *
 * Categories became tenant-scoped on 2026-08-02 (category_templates
 * feature) — tenant 1 is seeded on creation from a template, not from
 * this migration tool, so target category IDs no longer match source IDs
 * 1:1 and can't be preserved. Instead each source category is matched
 * against the tenant's existing categories by case-insensitive name; only
 * source categories with no match get a new tenant-scoped row inserted.
 */
class Step02Categories implements StepInterface
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
        $tenantId = $this->config->tenantId;

        $map = new MigrationMap($target);
        if (!$dryRun) {
            $map->ensureCategoryMapTable();
        }

        $rows = $source->query(
            "SELECT ID, cat_description FROM g_categories WHERE cat_type = 'lot' ORDER BY ID"
        )->fetchAll();

        $existingByName = [];
        $existingStmt = $target->prepare('SELECT id, name FROM categories WHERE tenant_id = :tenant_id');
        $existingStmt->execute(['tenant_id' => $tenantId]);
        foreach ($existingStmt->fetchAll() as $existingRow) {
            $existingByName[mb_strtolower(trim($existingRow['name']))] = (int) $existingRow['id'];
        }

        $insertStmt = $target->prepare(
            'INSERT INTO categories (tenant_id, name, slug, is_active, sort_order, created_at, updated_at)
             VALUES (:tenant_id, :name, :slug, 1, :sort_order, NOW(), NOW())'
        );

        $sortOrder = 1;

        foreach ($rows as $row) {
            $result->processed++;

            $sourceId = (int) $row['ID'];
            $name = $row['cat_description'];
            $nameKey = mb_strtolower(trim($name));

            if (!$dryRun && $map->getCategory($sourceId) !== null) {
                $this->logger->warn("Source category id={$sourceId} already mapped — skipping.");
                $result->skipped++;
                $sortOrder++;
                continue;
            }

            $existingId = $existingByName[$nameKey] ?? null;

            if ($existingId !== null) {
                $this->logger->info("Source category id={$sourceId} name=\"{$name}\" matched existing tenant category id={$existingId} — mapping, no insert.");
                if (!$dryRun) {
                    $map->setCategory($sourceId, $existingId);
                }
                $result->skipped++;
                $sortOrder++;
                continue;
            }

            $slug = Support::slugify($name);

            if ($dryRun) {
                $this->logger->dryRun("Would insert new tenant category name=\"{$name}\" slug={$slug} sort_order={$sortOrder} and map source id={$sourceId} to it.");
                $result->inserted++;
                $sortOrder++;
                continue;
            }

            $insertStmt->execute([
                'tenant_id' => $tenantId,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ]);
            $targetId = (int) $target->lastInsertId();
            $map->setCategory($sourceId, $targetId);
            $existingByName[$nameKey] = $targetId;

            $result->inserted++;
            $sortOrder++;
        }

        $this->logger->info("Categories: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} matched/skipped.");

        return $result;
    }
}
