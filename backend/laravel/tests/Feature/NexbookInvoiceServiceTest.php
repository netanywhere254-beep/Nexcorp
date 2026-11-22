<?php

namespace Tests\\Feature;

use App\\Models\\NexbookInvoice;
use App\\Models\\NexispAccount;
use App\\Models\\Organization;
use App\\Services\\Nexisp\\NexbookInvoiceService;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Validation\\ValidationException;
use Tests\\TestCase;

class NexbookInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_draft_is_numbered_and_issue_posts_balanced_journal_atomically(): void
    {
        [$organization, $receivable, $revenue, $taxPayable] = $this->accounts();

        $service = app(NexbookInvoiceService::class);
        $draft = $service->createDraft($organization->id, [
            'customer_name' => 'Sample Home Customer',
            'customer_email' => 'customer@example.test',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-12-06',
            'currency' => 'KES',
            'tax_rate_basis_points' => 1600,
            'lines' => [
                [
                    'revenue_account_id' => $revenue->id,
                    'description' => 'Home internet subscription',
                    'quantity' => '2',
                    'unit_price' => '100.00',
                ],
            ],
        ]);

        $this->assertSame('NB-2026-000001', $draft->invoice_number);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('200.00', $draft->subtotal);
        $this->assertSame('32.00', $draft->tax_total);
        $this->assertSame('232.00', $draft->total);

        $issued = $service->issue($organization->id, $draft->id, $receivable->id, $taxPayable->id);

        $this->assertSame('issued', $issued->status);
        $this->assertNotNull($issued->journal_entry_id);
        $this->assertSame('posted', $issued->journalEntry->status);
        $this->assertSame('KES', $issued->journalEntry->currency);
        $this->assertCount(3, $issued->journalEntry->lines);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'nexbook.invoice.issued',
            'subject_id' => (string) $issued->id,
        ]);

        $debits = $issued->journalEntry->lines->sum(fn ($line) => (float) $line->debit);
        $credits = $issued->journalEntry->lines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(232.00, $debits);
        $this->assertEquals(232.00, $credits);
    }

    public function test_invoice_issue_is_idempotently_rejected_after_first_issue(): void
    {
        [$organization, $receivable, $revenue] = $this->accounts();
        $service = app(NexbookInvoiceService::class);
        $draft = $service->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-11-22',
            'lines' => [[
                'revenue_account_id' => $revenue->id,
                'description' => 'Installation',
                'quantity' => '1',
                'unit_price' => '500.00',
            ]],
        ]);

        $service->issue($organization->id, $draft->id, $receivable->id);

        $this->expectException(ValidationException::class);
        $service->issue($organization->id, $draft->id, $receivable->id);
    }

    public function test_invoice_lines_cannot_reference_another_organizations_revenue_account(): void
    {
        [$organization] = $this->accounts();
        $other = Organization::create([
            'name' => 'Foreign ISP',
            'slug' => 'foreign-isp',
            'type' => 'isp',
            'status' => 'active',
        ]);
        $foreignRevenue = $this->account($other->id, '4100', 'Foreign Revenue', 'revenue', 'credit');

        $this->expectException(ValidationException::class);
        app(NexbookInvoiceService::class)->createDraft($organization->id, [
            'customer_name' => 'Sample Customer',
            'issue_date' => '2026-11-22',
            'due_date' => '2026-11-22',
            'lines' => [[
                'revenue_account_id' => $foreignRevenue->id,
                'description' => 'Cross tenant attempt',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ]);
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
