<?php

declare(strict_types=1);

/**
 * Seeds client_locations from each auction's a_auctions.locations field and
 * links each lot to the matching location.
 *
 * a_auctions.locations holds either a single unmarked address block (one
 * implicit location) or several blocks marked "<u>LOCATION N:</u>" when a
 * client has multiple participating sites for that auction. When there are
 * multiple locations, each lot's source description ends with a trailing
 * "Location N" reference (punctuation before it is inconsistent — ";", ".",
 * or nothing) that ties it to one of them; single-location auctions have no
 * such suffix since it's implied. This step creates one client_locations
 * row per (auction, location number) — fresh per auction, not deduplicated
 * against a client's other auctions — and sets lots.location_id
 * accordingly, best-effort stripping the source suffix text from the
 * already-migrated lots.title/description where it's visible.
 *
 * Like step 14, this is a seeding function: rows are inserted with
 * status=needs_review for operators to proof before real use.
 */
class Step15ClientLocations implements StepInterface
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

        if (!Support::tableExists($target, '_migration_lot_map')) {
            $this->logger->error('_migration_lot_map not found — run step 10 (Lots) before this step.');
            $result->errors[] = 'Missing _migration_lot_map';

            return $result;
        }

        $prefixes = Support::sourceAuctionPrefixes($source);
        $this->logger->info(count($prefixes) . ' auction table set(s) found in source.');

        foreach ($prefixes as $prefix) {
            $this->processAuction($prefix, $source, $target, $dryRun, $result);
        }

        $this->logger->info("ClientLocations: {$result->processed} processed, {$result->inserted} inserted, {$result->skipped} skipped.");

        return $result;
    }

    private function processAuction(string $prefix, PDO $source, PDO $target, bool $dryRun, StepResult $result): void
    {
        $auctionNum = Support::auctionNumberFromPrefix($prefix);

        $auctionStmt = $target->prepare('SELECT id, client_id FROM auctions WHERE id = :id');
        $auctionStmt->execute(['id' => $auctionNum]);
        $auction = $auctionStmt->fetch();

        if ($auction === false) {
            return;
        }

        $existsStmt = $target->prepare('SELECT 1 FROM client_locations WHERE auction_id = :auction_id LIMIT 1');
        $existsStmt->execute(['auction_id' => $auctionNum]);
        if ($existsStmt->fetchColumn() !== false) {
            $this->logger->warn("Auction {$auctionNum} already has client_locations rows — skipping to avoid duplicates.");

            return;
        }

        $locStmt = $source->prepare('SELECT locations FROM a_auctions WHERE workspace_id = :id');
        $locStmt->execute(['id' => $auctionNum]);
        $locationsText = trim((string) $locStmt->fetchColumn());

        if ($locationsText === '') {
            return;
        }

        $blocks = $this->parseLocationBlocks($locationsText);
        if (empty($blocks)) {
            return;
        }

        $insertLocStmt = $target->prepare(
            'INSERT INTO client_locations (
                client_id, auction_id, source_location_number, address,
                contact_info, inspection_notes, status, created_at, updated_at
             ) VALUES (
                :client_id, :auction_id, :source_location_number, :address,
                :contact_info, :inspection_notes, \'needs_review\', NOW(), NOW()
             )'
        );

        $locationIdByNumber = [];

        foreach ($blocks as $number => $block) {
            [$address, $contactInfo, $inspectionNotes] = $this->splitLocationBlock($block);

            if ($dryRun) {
                $this->logger->dryRun("Would insert client_location auction={$auctionNum} number={$number}");
                $locationIdByNumber[$number] = -1;
                continue;
            }

            $insertLocStmt->execute([
                'client_id' => (int) $auction['client_id'],
                'auction_id' => $auctionNum,
                'source_location_number' => $number,
                'address' => $address,
                'contact_info' => $contactInfo,
                'inspection_notes' => $inspectionNotes,
            ]);
            $locationIdByNumber[$number] = (int) $target->lastInsertId();
        }

        $this->linkLots($prefix, $auctionNum, $source, $target, $dryRun, $result, $locationIdByNumber);
    }

    private function linkLots(
        string $prefix,
        int $auctionNum,
        PDO $source,
        PDO $target,
        bool $dryRun,
        StepResult $result,
        array $locationIdByNumber
    ): void {
        $invTable = "{$prefix}_inventory";
        if (!Support::tableExists($source, $invTable)) {
            return;
        }

        $singleLocationId = count($locationIdByNumber) === 1 ? reset($locationIdByNumber) : null;

        $lotStmt = $source->query("SELECT ID AS inv_id, description FROM {$invTable} ORDER BY ID");
        $updateLotStmt = $target->prepare(
            'UPDATE lots SET location_id = :location_id, title = :title, description = :description, updated_at = NOW() WHERE id = :id'
        );
        $mapStmt = $target->prepare(
            'SELECT target_lot_id FROM _migration_lot_map WHERE source_auction = :auction AND source_lot_id = :source_lot_id'
        );
        $lotFieldsStmt = $target->prepare('SELECT title, description FROM lots WHERE id = :id');

        foreach ($lotStmt->fetchAll() as $row) {
            $result->processed++;
            $sourceLotId = (int) $row['inv_id'];

            $mapStmt->execute(['auction' => $auctionNum, 'source_lot_id' => $sourceLotId]);
            $targetLotId = $mapStmt->fetchColumn();
            if ($targetLotId === false) {
                $result->skipped++;
                continue;
            }
            $targetLotId = (int) $targetLotId;

            if ($singleLocationId !== null) {
                $locationId = $singleLocationId;
            } else {
                $number = $this->extractTrailingLocationNumber((string) $row['description']);
                $locationId = $number !== null ? ($locationIdByNumber[$number] ?? null) : null;

                if ($locationId === null) {
                    $this->logger->warn("Auction {$auctionNum} lot {$sourceLotId}: could not resolve a location from description — leaving location_id NULL.");
                    $result->skipped++;
                    continue;
                }
            }

            if ($dryRun) {
                $this->logger->dryRun("Would set lots.id={$targetLotId} location_id=" . ($locationId === -1 ? 'NEW' : $locationId));
                $result->inserted++;
                continue;
            }

            $lotFieldsStmt->execute(['id' => $targetLotId]);
            $fields = $lotFieldsStmt->fetch();
            [$title, $description] = $this->stripTrailingLocationSuffix(
                $fields !== false ? (string) $fields['title'] : '',
                $fields !== false ? (string) ($fields['description'] ?? '') : ''
            );

            $updateLotStmt->execute([
                'location_id' => $locationId,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'id' => $targetLotId,
            ]);
            $result->inserted++;
        }
    }

    /**
     * @return array<int, string> location number => raw block content
     */
    private function parseLocationBlocks(string $locationsText): array
    {
        $sections = Support::splitMarkedSections($locationsText);

        $blocks = [];
        foreach ($sections as $section) {
            if (preg_match('/^LOCATION\s+(\d+)$/i', $section['label'], $m)) {
                $blocks[(int) $m[1]] = $section['content'];
            }
        }

        if (!empty($blocks)) {
            return $blocks;
        }

        return [1 => $locationsText];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [address, contact_info, inspection_notes]
     */
    private function splitLocationBlock(string $block): array
    {
        if (preg_match('/^(.*?)(<br\s*\/?>\s*Contact\s*:.*)$/isu', $block, $m)) {
            $address = trim($m[1]);
            $rest = $m[2];

            if (preg_match('/^(.*?)(<br\s*\/?>\s*Inspections?\s*:.*)$/isu', $rest, $m2)) {
                return [$address !== '' ? $address : null, trim($m2[1]) ?: null, trim($m2[2]) ?: null];
            }

            return [$address !== '' ? $address : null, trim($rest) ?: null, null];
        }

        return [trim($block) ?: null, null, null];
    }

    private function extractTrailingLocationNumber(string $description): ?int
    {
        if (preg_match('/Location\s+(\d+)\s*$/iu', trim($description), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string} [title, description]
     */
    private function stripTrailingLocationSuffix(string $title, string $description): array
    {
        $suffixAtEnd = '/\s*[;.,]?\s*Location\s+\d+\s*$/iu';
        $suffixMidParagraph = '/\s*[;.,]?\s*Location\s+\d+\s*(\n\n)/iu';

        $strippedTitle = preg_replace($suffixAtEnd, '', $title);
        if ($strippedTitle !== $title) {
            return [rtrim((string) $strippedTitle), $description];
        }

        $strippedDescription = preg_replace($suffixMidParagraph, '$1', $description);
        if ($strippedDescription !== $description) {
            return [$title, trim((string) $strippedDescription)];
        }

        $strippedDescription = preg_replace($suffixAtEnd, '', $description);
        if ($strippedDescription !== $description) {
            return [$title, rtrim((string) $strippedDescription)];
        }

        return [$title, $description];
    }
}
