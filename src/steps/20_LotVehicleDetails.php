<?php

declare(strict_types=1);

/**
 * Parses {NNN}_inventory.description for vehicle attributes and seeds
 * lot_vehicle_details. Gated on finding a VIN-shaped token in the text —
 * a stronger, self-validating signal than category-based gating, since
 * source categories mix vehicles into broad buckets ("Agriculture/Farm
 * Equipment") alongside non-titled equipment. Descriptions follow a
 * semi-consistent semicolon-delimited format, e.g.:
 *   "2020 Chevrolet Express Van; VIN 1GAZGNFP3L1255690; 347,793 Miles
 *    showing; 4.3L Gas; Auto; ..."
 * title_status comes from {NNN}_inventory.disclosure_id -> a_disclosures,
 * not the free text — it's the more reliable of the two sources. Also sets
 * lots.requires_title when the resolved disclosure implies a real title
 * changes hands. This is a best-effort seed, same spirit as steps 14/15:
 * rows are only written when fields were actually extracted, nothing is
 * fabricated on a parse miss.
 */
class Step20LotVehicleDetails implements StepInterface
{
    private const FUEL_KEYWORDS = ['Gas', 'Diesel', 'Electric', 'Hybrid', 'Propane', 'CNG'];
    private const COLOR_KEYWORDS = ['White', 'Black', 'Red', 'Blue', 'Silver', 'Gray', 'Grey', 'Green', 'Gold', 'Tan', 'Maroon', 'Brown', 'Beige', 'Orange', 'Yellow'];

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

        if (!Support::tableExists($target, '_migration_lot_map')) {
            $this->logger->error('_migration_lot_map not found — run step 10 (Lots) before this step.');
            $result->errors[] = 'Missing _migration_lot_map';

            return $result;
        }

        $disclosureTitles = $this->loadDisclosureTitles($source);

        $mapStmt = $target->prepare(
            'SELECT target_lot_id FROM _migration_lot_map WHERE source_auction = :auction AND source_lot_id = :source_lot_id'
        );
        $existsStmt = $target->prepare('SELECT 1 FROM lot_vehicle_details WHERE lot_id = :lot_id');
        $insertStmt = $target->prepare(
            'INSERT INTO lot_vehicle_details (
                lot_id, make, model, model_year, mileage, tach_hours,
                identification_number, title_status, color, fuel_type,
                transmission, created_at, updated_at
             ) VALUES (
                :lot_id, :make, :model, :model_year, :mileage, :tach_hours,
                :identification_number, :title_status, :color, :fuel_type,
                :transmission, NOW(), NOW()
             )'
        );
        $updateRequiresTitleStmt = $target->prepare(
            'UPDATE lots SET requires_title = 1, updated_at = NOW() WHERE id = :id'
        );

        $prefixes = Support::sourceAuctionPrefixes($source);
        $this->logger->info(count($prefixes) . ' auction table set(s) found in source.');

        foreach ($prefixes as $prefix) {
            $invTable = "{$prefix}_inventory";
            if (!Support::tableExists($source, $invTable)) {
                continue;
            }

            $auctionNum = Support::auctionNumberFromPrefix($prefix);
            $rows = $source->query("SELECT ID AS inv_id, description, disclosure_id FROM {$invTable} ORDER BY ID")->fetchAll();

            foreach ($rows as $row) {
                $result->processed++;
                $this->processLot(
                    $row,
                    $auctionNum,
                    $disclosureTitles,
                    $mapStmt,
                    $existsStmt,
                    $insertStmt,
                    $updateRequiresTitleStmt,
                    $dryRun,
                    $result
                );
            }
        }

        $this->logger->info("LotVehicleDetails: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    /**
     * @return array<int, string> a_disclosures.ID => disc_title
     */
    private function loadDisclosureTitles(PDO $source): array
    {
        $map = [];
        foreach ($source->query('SELECT ID AS id, disc_title AS title FROM a_disclosures')->fetchAll() as $row) {
            $map[(int) $row['id']] = (string) $row['title'];
        }

        return $map;
    }

    /**
     * @param array<int, string> $disclosureTitles
     */
    private function processLot(
        array $row,
        int $auctionNum,
        array $disclosureTitles,
        PDOStatement $mapStmt,
        PDOStatement $existsStmt,
        PDOStatement $insertStmt,
        PDOStatement $updateRequiresTitleStmt,
        bool $dryRun,
        StepResult $result
    ): void {
        $sourceLotId = (int) $row['inv_id'];
        $description = (string) $row['description'];

        $fields = $this->parseDescription($description);
        if ($fields === null) {
            $result->skipped++;

            return;
        }

        $mapStmt->execute(['auction' => $auctionNum, 'source_lot_id' => $sourceLotId]);
        $targetLotId = $mapStmt->fetchColumn();
        if ($targetLotId === false) {
            $result->skipped++;

            return;
        }
        $targetLotId = (int) $targetLotId;

        if (!$dryRun) {
            $existsStmt->execute(['lot_id' => $targetLotId]);
            if ($existsStmt->fetchColumn() !== false) {
                $result->skipped++;

                return;
            }
        }

        $disclosureId = $row['disclosure_id'] !== null ? (int) $row['disclosure_id'] : null;
        $discTitle = $disclosureId !== null ? ($disclosureTitles[$disclosureId] ?? null) : null;
        $titleStatus = $this->normalizeTitleStatus($discTitle);
        $requiresTitle = $discTitle !== null && stripos($discTitle, 'title') !== false;

        if ($dryRun) {
            $this->logger->dryRun(
                "Would insert lot_vehicle_details lot_id={$targetLotId} make=\"{$fields['make']}\" model=\"{$fields['model']}\" "
                . "year={$fields['model_year']} vin={$fields['identification_number']} title_status={$titleStatus}"
            );
            $result->inserted++;

            return;
        }

        $insertStmt->execute([
            'lot_id' => $targetLotId,
            'make' => $fields['make'],
            'model' => $fields['model'],
            'model_year' => $fields['model_year'],
            'mileage' => $fields['mileage'],
            'tach_hours' => $fields['tach_hours'],
            'identification_number' => $fields['identification_number'],
            'title_status' => $titleStatus,
            'color' => $fields['color'],
            'fuel_type' => $fields['fuel_type'],
            'transmission' => $fields['transmission'],
        ]);

        if ($requiresTitle) {
            $updateRequiresTitleStmt->execute(['id' => $targetLotId]);
        }

        $result->inserted++;
    }

    /**
     * @return array{make: ?string, model: ?string, model_year: ?int, mileage: ?int,
     *               tach_hours: ?float, identification_number: string, color: ?string,
     *               fuel_type: ?string, transmission: ?string}|null
     */
    private function parseDescription(string $description): ?array
    {
        if (!preg_match('/\bVIN\s+([A-Z0-9]{10,17})\b/i', $description, $vinMatch)) {
            return null;
        }

        $segments = array_map('trim', explode(';', $description));

        $make = null;
        $model = null;
        $modelYear = null;
        if (preg_match('/^(\d{4})\s+(\S+)\s+(.+)$/', $segments[0] ?? '', $ym)) {
            $modelYear = (int) $ym[1];
            $make = $ym[2];
            $model = trim($ym[3]);
        }

        $mileage = null;
        $tachHours = null;
        $fuelType = null;
        $transmission = null;
        $color = null;

        foreach ($segments as $segment) {
            if ($mileage === null && preg_match('/([\d,]+)\s*Miles\s*showing/i', $segment, $mm)) {
                $mileage = (int) str_replace(',', '', $mm[1]);
            }
            if ($tachHours === null && preg_match('/([\d.,]+)\s*(?:Hours|Hrs)\b/i', $segment, $hm)) {
                $tachHours = (float) str_replace(',', '', $hm[1]);
            }
            if ($fuelType === null) {
                foreach (self::FUEL_KEYWORDS as $fuel) {
                    if (preg_match('/\b' . preg_quote($fuel, '/') . '\b/i', $segment)) {
                        $fuelType = $fuel;
                        break;
                    }
                }
            }
            if ($transmission === null && preg_match('/\b(Auto(?:matic)?|Manual)\b/i', $segment, $tm)) {
                $transmission = stripos($tm[1], 'auto') === 0 ? 'Automatic' : 'Manual';
            }
            if ($color === null) {
                foreach (self::COLOR_KEYWORDS as $colorWord) {
                    if (preg_match('/\b' . preg_quote($colorWord, '/') . '\b/i', $segment)) {
                        $color = $colorWord;
                        break;
                    }
                }
            }
        }

        return [
            'make' => $make,
            'model' => $model,
            'model_year' => $modelYear,
            'mileage' => $mileage,
            'tach_hours' => $tachHours,
            'identification_number' => strtoupper($vinMatch[1]),
            'color' => $color,
            'fuel_type' => $fuelType,
            'transmission' => $transmission,
        ];
    }

    private function normalizeTitleStatus(?string $discTitle): ?string
    {
        if ($discTitle === null) {
            return null;
        }

        $lower = strtolower($discTitle);

        if (str_contains($lower, 'salvage')) {
            return 'salvage';
        }
        if (str_contains($lower, 'nonrepairable') || str_contains($lower, 'junked')) {
            return 'nonrepairable';
        }
        if (str_contains($lower, 'impounded')) {
            return 'impounded';
        }
        if (str_contains($lower, 'donated')) {
            return 'donated';
        }
        if (str_contains($lower, 'seized') || str_contains($lower, 'forfeiture')) {
            return 'seized';
        }
        if (str_contains($lower, 'bank')) {
            return 'bank_foreclosure';
        }

        return 'clean';
    }
}
