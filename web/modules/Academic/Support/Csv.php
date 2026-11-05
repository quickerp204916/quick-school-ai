<?php

namespace Modules\Academic\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads for reports. Opens cleanly in Excel (UTF-8 with BOM). */
final class Csv
{
    /** @param iterable<array<int, scalar|null>> $rows */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                // Stop spreadsheet formulas in user-entered text.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
