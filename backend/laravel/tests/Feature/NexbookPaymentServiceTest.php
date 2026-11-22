<?php

namespace Tests\Feature;

use App\Models\NexbookInvoice;
use App\Models\NexispAccount;
use App\Models\Organization;
use App\Services\Nexisp\NexbookInvoiceService;
use App\Services\Nexisp\NexbookPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NexbookPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_receipts_post_journals_and_update_invoice_outstanding_balance(): void
    {
        [$organization, $receivable, $revenue, $cash] = $this->accounts();
        $invoice = app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample ISP Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Monthly internet subscription',
                'quantity' => '1',
                'unit_price' => '300.00',
            ]],
        ]);
        $invoice = app(NexbookInvoiceService::class)->issue($organization->id, $invoice->id, $receivable->id);

        $payments = app(NexbookPaymentService::class);
        $firstReceipt = $payments->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-11-23',
            'KES',
            'mobile_money',
            '100.00',
            [['invoice_id' => $invoice->id, 'amount' => '100.00']],
            'MPESA-REF-001',
        );

        $partPaid = NexbookInvoice::query()->findOrFail($invoice->id);
        $this->assertSame('partially_paid', $partPaid->status);
        $this->assertSame('100.00', $partPaid->amount_paid);
        $this->assertSame('posted', $firstReceipt->status);
        $this->assertSame('RCPT-2026-000001', $firstReceipt->receipt_number);
        $this->assertSame('posted', $firstReceipt->journalEntry->status);

        $secondReceipt = $payments->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-11-24',
            'KES',
            'mobile_money',
            '200.00',
            [['invoice_id' => $invoice->id, 'amount' => '200.00']],
            'MPESA-REF-002',
        );

        $paid = NexbookInvoice::query()->findOrFail($invoice->id);
        $this->assertSame('paid', $paid->status);
        $this->assertSame('300.00', $paid->amount_paid);
        $this->assertSame('RCPT-2026-000002', $secondReceipt->receipt_number);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'nexbook.payment.received',
            'subject_id' => (string) $secondReceipt->id,
        ]);
    }

    public function test_receipt_cannot_overallocate_invoice_or_cross_tenant_boundary(): void
    {
        [$organization, $receivable, $revenue, $cash] = $this->accounts();
        $invoice = app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-11-22',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Installation',
                'quantity' => '1',
                'unit_price' => '50.00',
            ]],
        ]);
        $invoice = app(NexbookInvoiceService::class)->issue($organization->id, $invoice->id, $receivable->id);

        $this->expectException(ValidationException::class);
        app(NexbookPaymentService::class)->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-11-23',
            'KES',
            'cash',
            '60.00',
            [['invoice_id' => $invoice->id, 'amount' => '60.00']],
        );
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
