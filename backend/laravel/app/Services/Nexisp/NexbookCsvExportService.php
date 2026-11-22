<?php

namespace App\Services\Nexisp;

use Symfony\Component\HttpFoundation\StreamedResponse;

class NexbookCsvExportService
{
    /**
     * Stream UTF-8 CSV with a BOM for spreadsheet compatibility.
     * Callers must pass untrusted text through safeText() and numeric values
     * as numeric strings so legitimate negative balances remain numeric.
     */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '\\');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function safeText(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[\s]*[=+@\-\t\r]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
