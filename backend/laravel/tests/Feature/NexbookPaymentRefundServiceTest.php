<?php

namespace Tests\Feature;

use App\Models\NexbookInvoice;
use App\Models\NexispAccount;
use App\Models\Organization;
use App\Services\Nexisp\NexbookInvoiceService;
use App\Services\Nexisp\NexbookPaymentService;
use App\Services\Nexisp\NexbookPaymentRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NexbookPaymentRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_reverses_receivable_and_updates_invoice_paid_balance(): void
    {
        [$organization, $receivable, $revenue, $cash] = $this->accounts();
        $invoice = app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Monthly internet plan',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ]);
        $invoice = app(NexbookInvoiceService::class)->issue($organization->id, $invoice->id, $receivable->id);
        $receipt = app(NexbookPaymentService::class)->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-11-23',
            'KES',
            'mobile_money',
            '100.00',
            [['invoice_id' => $invoice->id, 'amount' => '100.00']],
            'MPESA-REFUND-001',
        );

        $refund = app(NexbookPaymentRefundService::class)->refund(
            $organization->id,
            $receipt->id,
            $cash->id,
            $receivable->id,
            '2026-11-24',
            'mobile_money',
            'Customer paid twice',
            [['invoice_id' => $invoice->id, 'amount' => '30.00']],
            'MPESA-REVERSAL-001',
        );

        $updatedInvoice = NexbookInvoice::query()->findOrFail($invoice->id);
        $this->assertSame('70.00', $updatedInvoice->amount_paid);
        $this->assertSame('partially_paid', $updatedInvoice->status);
        $this->assertSame('posted', $refund->status);
        $this->assertSame('RF-2026-000001', $refund->refund_number);
        $this->assertSame('posted', $refund->journalEntry->status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'nexbook.payment.refunded',
            'subject_id' => (string) $refund->id,
        ]);
    }

    public function test_refund_cannot_exceed_the_original_receipt_allocation(): void
    {
        [$organization, $receivable, $revenue, $cash] = $this->accounts();
        $invoice = app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Installation',
                'quantity' => '1',
                'unit_price' => '50.00',
            ]],
        ]);
        $invoice = app(NexbookInvoiceService::class)->issue($organization->id, $invoice->id, $receivable->id);
        $receipt = app(NexbookPaymentService::class)->receive(
            $organization->id,
            $cash->id,
            $receivable->id,
            '2026-11-23',
            'KES',
            'cash',
            '50.00',
            [['invoice_id' => $invoice->id, 'amount' => '50.00']],
        );

        $this->expectException(ValidationException::class);
        app(NexbookPaymentRefundService::class)->refund(
            $organization->id,
            $receipt->id,
            $cash->id,
            $receivable->id,
            '2026-11-24',
            'cash',
            'Over-refund attempt',
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
