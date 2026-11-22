<?php
declare(strict_types=1);

namespace App\Core;

final class Csv
{
    /**
     * Streams a CSV download. Cells beginning with = + - @ (or tab / CR) are
     * prefixed with an apostrophe to prevent spreadsheet formula injection.
     *
     * @param array<string,string> $columns key => header label
     */
    public static function download(string $filename, array $columns, iterable $rows): never
    {
        $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename) . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and names correctly
        fputcsv($out, array_values($columns), ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[] = self::safe($row[$key] ?? '');
            }
            fputcsv($out, $line, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    private static function safe(mixed $v): string
    {
        $s = (string) $v;
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s) && !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return "'" . $s;
        }
        return $s;
    }
}
