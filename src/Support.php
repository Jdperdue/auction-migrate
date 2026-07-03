<?php

declare(strict_types=1);

class Support
{
    public static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'item';
    }

    /**
     * Splits a full name into [firstName, lastName] on the first space.
     * A name with no space is treated entirely as the first name.
     */
    public static function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        $pos = strpos($fullName, ' ');

        if ($pos === false) {
            return [$fullName !== '' ? $fullName : 'Unknown', ''];
        }

        return [substr($fullName, 0, $pos), trim(substr($fullName, $pos + 1))];
    }

    /**
     * Converts a source table-set prefix like "007" into an int auction number.
     */
    public static function auctionNumberFromPrefix(string $prefix): int
    {
        return (int) $prefix;
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Splits source HTML text on "<u>LABEL:</u>" markers (used throughout
     * a_auctions.terms and a_auctions.locations) into ordered
     * [label, content] pairs, where content runs up to the next marker or
     * end of string. Text before the first marker (if any) is discarded —
     * callers that need to handle unmarked content check for an empty
     * return array themselves.
     *
     * @return array<int, array{label: string, content: string}>
     */
    public static function splitMarkedSections(string $html): array
    {
        $parts = preg_split('/<u>\s*([^<]+?)\s*:\s*<\/u>/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) < 3) {
            return [];
        }

        $sections = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[] = [
                'label' => trim($parts[$i]),
                'content' => trim($parts[$i + 1] ?? ''),
            ];
        }

        return $sections;
    }

    /**
     * Returns zero-padded auction prefixes ("001".."300") that have a
     * matching {NNN}_inventory table in the source database.
     *
     * @return string[]
     */
    public static function sourceAuctionPrefixes(PDO $source): array
    {
        $stmt = $source->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name REGEXP '^[0-9]{3}_inventory$'
             ORDER BY table_name"
        );

        $prefixes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
            $prefixes[] = substr($tableName, 0, 3);
        }

        return $prefixes;
    }
}
