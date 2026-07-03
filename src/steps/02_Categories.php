<?php

declare(strict_types=1);

/**
 * Migrates g_categories WHERE cat_type = 'lot' into categories, preserving
 * source IDs. Categories are global (no tenant_id column).
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

        $rows = $source->query(
            "SELECT ID, cat_description FROM g_categories WHERE cat_type = 'lot' ORDER BY ID"
        )->fetchAll();

        $checkStmt = $target->prepare('SELECT id FROM categories WHERE id = :id');
        $insertStmt = $target->prepare(
            'INSERT INTO categories (id, name, slug, is_active, sort_order, created_at, updated_at)
             VALUES (:id, :name, :slug, 1, :sort_order, NOW(), NOW())'
        );

        $sortOrder = 1;

        foreach ($rows as $row) {
            $result->processed++;

            $id = (int) $row['ID'];
            $name = $row['cat_description'];
            $slug = Support::slugify($name);

            $checkStmt->execute(['id' => $id]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->logger->warn("Category id={$id} already exists — skipping.");
                $result->skipped++;
                $sortOrder++;
                continue;
            }

            if ($dryRun) {
                $this->logger->dryRun("Would insert category id={$id} name=\"{$name}\" slug={$slug} sort_order={$sortOrder}");
                $result->inserted++;
                $sortOrder++;
                continue;
            }

            $insertStmt->execute([
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ]);
            $result->inserted++;
            $sortOrder++;
        }

        $this->logger->info("Categories: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }
}
