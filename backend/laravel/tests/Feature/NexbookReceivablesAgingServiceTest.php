<?php

namespace Tests\Feature;

use App\Models\NexispAccount;
use App\Models\Organization;
use App\Services\Nexisp\NexbookInvoiceService;
use App\Services\Nexisp\NexbookPaymentService;
use App\Services\Nexisp\NexbookReceivablesAgingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexbookReceivablesAgingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ageing_report_groups_outstanding_balances_by_due_bucket_and_currency(): void
    {
        [$organization, $receivable, $revenue, $cash] = $this->accounts();
        $invoices = app(NexbookInvoiceService::class);

        $overdue = $invoices->createDraft($organization->id, [
            'customer_name' => 'Alpha Customer',
            'issue_date' => '2026-09-25',
            'due_date' => '2026-10-05',
            'currency' => 'KES',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Monthly plan',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ]);
        $overdue = $invoices->issue($organization->id, $overdue->id, $receivable->id);

        $current = $invoices->createDraft($organization->id, [
            'customer_name' => 'Alpha Customer',
            'issue_date' => '2026-11-15',
            'due_date' => '2026-11-30',
            'currency' => 'KES',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Router rental',
                'quantity' => '1',
                'unit_price' => '200.00',
            ]],
        ]);
        $invoices->issue($organization->id, $current->id, $receivable->id);

        app(NexbookPaymentService::class)->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-10-10',
            'KES',
            'mobile_money',
            '40.00',
            [['invoice_id' => $overdue->id, 'amount' => '40.00']],
        );

        $report = app(NexbookReceivablesAgingService::class)->report($organization->id, '2026-11-22');

        $alphaKes = collect($report['customers'])->firstWhere('currency', 'KES');
        $this->assertSame('60.00', $alphaKes['31_60_days']);
        $this->assertSame('200.00', $alphaKes['current']);
        $this->assertSame('260.00', $alphaKes['total_outstanding']);
        $this->assertSame('260.00', $report['currency_totals']['KES']['total_outstanding']);
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
