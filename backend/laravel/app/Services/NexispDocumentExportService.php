<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class NexispDocumentExportService
{
    public function render(string $format, string $title, array $rows): array
    {
        $format = strtolower($format);

        return match ($format) {
            'csv' => [
                'content' => $this->csv($rows),
                'mime' => 'text/csv; charset=UTF-8',
                'extension' => 'csv',
            ],
            'xlsx', 'excel' => [
                'content' => $this->xlsx($title, $rows),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => 'xlsx',
            ],
            'pdf' => [
                'content' => $this->pdf($title, $rows),
                'mime' => 'application/pdf',
                'extension' => 'pdf',
            ],
            default => throw new RuntimeException('Unsupported export format. Choose csv, xlsx or pdf.'),
        };
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to create export stream.');
        }

        foreach ($rows as $row) {
            $safe = [];
            foreach ($row as $value) {
                $value = (string) ($value ?? '');
                if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $value = "'".$value;
                }
                $safe[] = $value;
            }
            fputcsv($stream, $safe);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".($contents === false ? '' : $contents);
    }

    private function xlsx(string $title, array $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Excel export requires the PHP zip extension. Enable ext-zip on the application server.');
        }

        $path = tempnam(sys_get_temp_dir(), 'nexisp-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate Excel export file.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create Excel workbook.');
            }

            $sheetRows = '';
            foreach ($rows as $rowIndex => $row) {
                $cells = '';
                foreach (array_values($row) as $columnIndex => $value) {
                    $reference = $this->columnName($columnIndex + 1).($rowIndex + 1);
                    $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string) ($value ?? '')).'</t></is></c>';
                }
                $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
            }

            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->xml(substr($title, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
            $zip->close();

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Unable to read generated Excel workbook.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    private function pdf(string $title, array $rows): string
    {
        $lines = [$title, 'Generated by NexISP Books', ''];
        foreach ($rows as $row) {
            $parts = array_values($row);
            if (count($parts) >= 2) {
                $lines[] = (string) $parts[0].': '.(string) $parts[1];
            } else {
                $lines[] = (string) ($parts[0] ?? '');
            }
        }

        $commands = "BT\n/F1 16 Tf\n50 790 Td\n";
        foreach ($lines as $index => $line) {
            if ($index === 1) {
                $commands .= "/F1 9 Tf\n";
            } elseif ($index > 1) {
                $commands .= "0 -17 Td\n/F1 11 Tf\n";
            }
            $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $line);
            $commands .= '('.$this->pdfEscape($encoded === false ? (string) $line : $encoded).") Tj\n";
        }
        $commands .= "ET\n";

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Length '.strlen($commands)." >>\nstream\n".$commands."endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($number = 1; $number <= 5; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function pdfEscape(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $value) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
