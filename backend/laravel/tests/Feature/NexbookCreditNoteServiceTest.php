<?php

namespace Tests\Feature;

use App\Models\NexispAccount;
use App\Models\Organization;
use App\Services\Nexisp\NexbookCreditNoteService;
use App\Services\Nexisp\NexbookInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexbookCreditNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_note_posts_revenue_and_receivable_reversals_against_invoice(): void
    {
        [$organization, $receivable, $revenue, $taxPayable] = $this->accounts();
        $invoice = app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'currency' => 'KES',
            'tax_rate_basis_points' => 1600,
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Monthly internet subscription',
                'quantity' => '1',
                'unit_price' => '1000.00',
            ]],
        ]);
        $invoice = app(NexbookInvoiceService::class)->issue($organization->id, $invoice->id, $receivable->id, $taxPayable->id);

        $note = app(NexbookCreditNoteService::class)->issue(
            $organization->id,
            $invoice->id,
            $receivable->id,
            $taxPayable->id,
            '2026-11-23',
            'Service outage adjustment',
            [['invoice_line_id' => $invoice->lines->first()->id, 'amount' => '250.00']],
        );

        $this->assertSame('issued', $note->status);
        $this->assertSame('250.00', $note->subtotal);
        $this->assertSame('40.00', $note->tax_total);
        $this->assertSame('290.00', $note->total);
        $this->assertSame('CN-2026-000001', $note->credit_note_number);
        $this->assertSame('posted', $note->journalEntry->status);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'nexbook.credit_note.issued',
            'subject_id' => (string) $note->id,
        ]);
    }

    public function test_credit_note_cannot_exceed_original_invoice_line_or_unpaid_balance(): void
    {
        [$organization, $receivable, $revenue, $taxPayable] = $this->accounts();
        $invoice = app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Installation',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ]);
        $invoice = app(NexbookInvoiceService::class)->issue($organization->id, $invoice->id, $receivable->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(NexbookCreditNoteService::class)->issue(
            $organization->id,
            $invoice->id,
            $receivable->id,
            null,
            '2026-11-23',
            'Over-credit attempt',
            [['invoice_line_id' => $invoice->lines->first()->id, 'amount' => '101.00']],
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
            $this->account($organization->id, '2200', 'Tax Payable', 'liability', 'credit'),
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
