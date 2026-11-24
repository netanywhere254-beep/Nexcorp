<?php

namespace Tests\Unit;

use App\Services\Nexisp\NexbookCsvExportService;
use PHPUnit\Framework\TestCase;

class NexbookCsvExportServiceTest extends TestCase
{
    public function test_untrusted_text_is_protected_from_spreadsheet_formula_injection(): void
    {
        $csv = new NexbookCsvExportService();

        $this->assertSame("'=HYPERLINK(\"https://bad.example\")", $csv->safeText('=HYPERLINK("https://bad.example")'));
        $this->assertSame("'+SUM(1,2)", $csv->safeText('+SUM(1,2)'));
        $this->assertSame("'  =1+1", $csv->safeText('  =1+1'));
        $this->assertSame("'\\t=1+1", $csv->safeText("\\t=1+1"));
        $this->assertSame("'-1+1", $csv->safeText('-1+1'));
        $this->assertSame('Ordinary customer', $csv->safeText('Ordinary customer'));
    }
}
