<?php

namespace Tests\Feature;

use App\Models\NexispAccount;
use App\Models\Organization;
use App\Services\Nexisp\NexbookCustomerStatementService;
use App\Services\Nexisp\NexbookCreditNoteService;
use App\Services\Nexisp\NexbookInvoiceService;
use App\Services\Nexisp\NexbookPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexbookCustomerStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_calculates_opening_running_and_closing_balances_per_currency(): void
    {
        [$organization, $receivable, $revenue, $cash] = $this->accounts();
        $invoices = app(NexbookInvoiceService::class);

        $kesInvoice = $invoices->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'currency' => 'KES',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'November internet plan',
                'quantity' => '1',
                'unit_price' => '300.00',
            ]],
        ]);
        $kesInvoice = $invoices->issue($organization->id, $kesInvoice->id, $receivable->id);

        $usdInvoice = $invoices->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'currency' => 'USD',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Cloud service',
                'quantity' => '1',
                'unit_price' => '20.00',
            ]],
        ]);
        $invoices->issue($organization->id, $usdInvoice->id, $receivable->id);

        app(NexbookPaymentService::class)->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-11-23',
            'KES',
            'mobile_money',
            '100.00',
            [['invoice_id' => $kesInvoice->id, 'amount' => '100.00']],
            'MPESA-STATEMENT-001',
        );

        app(NexbookCreditNoteService::class)->issue(
            $organization->id,
            $kesInvoice->id,
            $receivable->id,
            null,
            '2026-11-24',
            'Customer service adjustment',
            [['invoice_line_id' => $kesInvoice->lines->first()->id, 'amount' => '50.00']],
        );

        $statement = app(NexbookCustomerStatementService::class)->forCustomer(
            $organization->id,
            'Sample Customer',
            '2026-11-23',
            '2026-11-30',
        );

        $this->assertSame('300.00', $statement['currencies']['KES']['opening_balance']);
        $this->assertSame('150.00', $statement['currencies']['KES']['credits']);
        $this->assertSame('150.00', $statement['currencies']['KES']['closing_balance']);
        $this->assertSame('200.00', $statement['currencies']['KES']['transactions'][0]['balance']);\n        $this->assertSame('150.00', $statement['currencies']['KES']['transactions'][1]['balance']);

        $this->assertSame('20.00', $statement['currencies']['USD']['opening_balance']);
        $this->assertSame('0.00', $statement['currencies']['USD']['credits']);
        $this->assertSame('20.00', $statement['currencies']['USD']['closing_balance']);
    }

    private function accounts(): array
    {
        $organization = Organization::create([
            'name' => 'NetPulse WiFi',
            'slug' => 'netpulse-wifi',
            'type' => 'isp',
            'status' => 'active',
        ]);

        return [
            $organization,
            $this->account($organization->id, '1200', 'Accounts Receivable', 'asset', 'debit'),
            $this->account($organization->id, '4100', 'Internet Revenue', 'revenue', 'credit'),
            $this->account($organization->id, '1100', 'Cash and Mobile Money', 'asset', 'debit'),
        ];
    }

    private function account(int $organizationId, string $code, string $name, string $type, string $normalBalance): NexispAccount
    {
        return NexispAccount::create([
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ]);
    }
}
