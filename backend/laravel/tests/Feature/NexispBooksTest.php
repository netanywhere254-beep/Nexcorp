<?php

namespace Tests\Feature;

use App\Models\NexispAccountingSetting;
use App\Models\NexispChartOfAccount;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\NexispJournalEntry;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\NexispAccountingService;
use App\Services\NexispDocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NexispBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nexisp.phone_hash_key' => 'nexisp-books-test-hmac-key']);
    }

    public function test_bootstrap_creates_native_chart_of_accounts_once_per_isp(): void
    {
        [$actor, $organization] = $this->fixture();
        $service = app(NexispAccountingService::class);

        $first = $service->bootstrap($organization, $actor);
        $second = $service->bootstrap($organization, $actor);

        $this->assertSame(12, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseCount('nexisp_chart_of_accounts', 12);
        $this->assertDatabaseHas('nexisp_accounting_settings', [
            'organization_id' => $organization->id,
            'operating_mode' => 'native',
            'native_ledger_enabled' => true,
        ]);
    }

    public function test_invoice_creation_calculates_tax_and_posts_balanced_journal(): void
    {
        [$actor, $organization, $customer] = $this->fixture();
        $service = app(NexispAccountingService::class);
        $service->bootstrap($organization, $actor);

        $tax = \App\Models\NexispTaxRate::create([
            'organization_id' => $organization->id,
            'code' => 'VAT16',
            'name' => 'VAT 16%',
            'rate_percent' => '16.0000',
            'active' => true,
        ]);

        $invoice = $service->createInvoice($actor, $organization, [
            'customer_id' => $customer->id,
            'currency' => 'KES',
            'items' => [
                ['description' => 'Home internet subscription', 'quantity' => 1, 'unit_price' => '1500.00', 'tax_rate_id' => $tax->id],
                ['description' => 'Installation', 'quantity' => 1, 'unit_price' => '500.00'],
            ],
        ]);

        $this->assertSame('2320.00', $invoice->amount);
        $this->assertSame('2000.00', $invoice->subtotal);
        $this->assertSame('320.00', $invoice->tax_amount);
        $this->assertCount(2, $invoice->items);
        $entry = NexispJournalEntry::where('organization_id', $organization->id)
            ->where('source_type', NexispInvoice::class)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertSame('2320.00', $entry->total_debit);
        $this->assertSame('2320.00', $entry->total_credit);
    }

    public function test_unbalanced_manual_journal_is_rejected(): void
    {
        [$actor, $organization] = $this->fixture();
        $service = app(NexispAccountingService::class);
        $service->bootstrap($organization, $actor);
        $cash = NexispChartOfAccount::where('organization_id', $organization->id)->where('code', '1000')->firstOrFail();
        $revenue = NexispChartOfAccount::where('organization_id', $organization->id)->where('code', '4000')->firstOrFail();

        $this->expectException(ValidationException::class);
        $service->postJournal($organization, $actor, [
            'entry_number' => 'UNBALANCED-1',
            'description' => 'Should not post',
            'currency' => 'KES',
            'entry_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 10000, 'credit' => 0],
                ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 9999],
            ],
        ]);
    }

    public function test_invoice_cannot_post_to_another_tenant_account(): void
    {
        [$actor, $organization, $customer] = $this->fixture();
        [$otherActor, $otherOrganization] = $this->fixture();
        $service = app(NexispAccountingService::class);
        $service->bootstrap($organization, $actor);
        $service->bootstrap($otherOrganization, $otherActor);
        $foreignAccount = NexispChartOfAccount::where('organization_id', $otherOrganization->id)->where('code', '1000')->firstOrFail();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->postJournal($organization, $actor, [
            'entry_number' => 'CROSS-TENANT-1',
            'description' => 'Cross tenant attempt',
            'currency' => 'KES',
            'entry_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $foreignAccount->id, 'debit' => 10000, 'credit' => 0],
                ['account_id' => NexispChartOfAccount::where('organization_id', $organization->id)->where('code', '4000')->value('id'), 'debit' => 0, 'credit' => 10000],
            ],
        ]);
    }

    public function test_receipt_requires_verified_matching_payment_and_posts_payment_journal_once(): void
    {
        [$actor, $organization, $customer] = $this->fixture();
        $service = app(NexispAccountingService::class);
        $service->bootstrap($organization, $actor);
        $invoice = $service->createInvoice($actor, $organization, [
            'customer_id' => $customer->id,
            'currency' => 'KES',
            'items' => [['description' => 'Monthly internet', 'quantity' => 1, 'unit_price' => '1500.00']],
        ]);

        $payment = PaymentTransaction::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'provider' => 'mpesa',
            'provider_reference' => 'MPESA-'.uniqid(),
            'idempotency_key' => 'pay-'.uniqid(),
            'status' => 'succeeded',
            'amount' => '1500.00',
            'currency' => 'KES',
            'verified_at' => now(),
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
        ]);

        $receipt = $service->issueReceipt($actor, $organization, $invoice->id, $payment->id);
        $again = $service->issueReceipt($actor, $organization, $invoice->id, $payment->id);

        $this->assertSame($receipt->id, $again->id);
        $this->assertSame('1500.00', $receipt->amount);
        $this->assertDatabaseCount('nexisp_receipts', 1);
        $this->assertDatabaseHas('nexisp_journal_entries', [
            'organization_id' => $organization->id,
            'source_type' => PaymentTransaction::class,
            'source_id' => $payment->id,
            'status' => 'posted',
        ]);
    }

    public function test_partial_verified_payments_issue_separate_receipts_and_settle_invoice(): void
    {
        [$actor, $organization, $customer] = $this->fixture();
        $service = app(NexispAccountingService::class);
        $service->bootstrap($organization, $actor);
        $invoice = $service->createInvoice($actor, $organization, [
            'customer_id' => $customer->id,
            'currency' => 'KES',
            'items' => [['description' => 'Monthly internet', 'quantity' => 1, 'unit_price' => '1500.00']],
        ]);

        foreach ([['500.00', 'PART-1'], ['1000.00', 'PART-2']] as [$amount, $reference]) {
            $payment = PaymentTransaction::create([
                'organization_id' => $organization->id,
                'user_id' => $actor->id,
                'provider' => 'mpesa',
                'provider_reference' => $reference.'-'.uniqid(),
                'idempotency_key' => $reference.'-'.uniqid(),
                'status' => 'succeeded',
                'amount' => $amount,
                'currency' => 'KES',
                'verified_at' => now(),
                'payable_type' => NexispInvoice::class,
                'payable_id' => $invoice->id,
            ]);
            $service->issueReceipt($actor, $organization, $invoice->id, $payment->id);
        }

        $this->assertDatabaseCount('nexisp_receipts', 2);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_receipt_rejects_unverified_payment(): void
    {
        [$actor, $organization, $customer] = $this->fixture();
        $service = app(NexispAccountingService::class);
        $service->bootstrap($organization, $actor);
        $invoice = $service->createInvoice($actor, $organization, [
            'customer_id' => $customer->id,
            'currency' => 'KES',
            'items' => [['description' => 'Monthly internet', 'quantity' => 1, 'unit_price' => '1500.00']],
        ]);
        $payment = PaymentTransaction::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'provider' => 'mpesa',
            'provider_reference' => 'PENDING-'.uniqid(),
            'idempotency_key' => 'pending-'.uniqid(),
            'status' => 'initiated',
            'amount' => '1500.00',
            'currency' => 'KES',
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->issueReceipt($actor, $organization, $invoice->id, $payment->id);
    }

    public function test_accounting_export_generates_csv_and_pdf_and_valid_xlsx_when_zip_is_available(): void
    {
        $exporter = app(NexispDocumentExportService::class);
        $rows = [['Field', 'Value'], ['Invoice', 'INV-1'], ['Amount', '1500.00']];

        $csv = $exporter->render('csv', 'Invoice', $rows);
        $this->assertSame('csv', $csv['extension']);
        $this->assertStringContainsString('INV-1', $csv['content']);

        $pdf = $exporter->render('pdf', 'Invoice', $rows);
        $this->assertSame('pdf', $pdf['extension']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf['content']);

        if (class_exists(\ZipArchive::class)) {
            $xlsx = $exporter->render('xlsx', 'Invoice', $rows);
            $this->assertSame('xlsx', $xlsx['extension']);
            $this->assertStringStartsWith("PK", $xlsx['content']);
        } else {
            $this->markTestSkipped('PHP zip extension is not installed in this CI image.');
        }
    }

    public function test_native_ledger_cannot_be_disabled_when_switching_modes(): void
    {
        [$actor, $organization] = $this->fixture();
        $settings = NexispAccountingSetting::create([
            'organization_id' => $organization->id,
            'operating_mode' => 'hybrid',
            'base_currency' => 'KES',
            'native_ledger_enabled' => true,
        ]);

        $this->assertTrue($settings->native_ledger_enabled);
        $this->assertSame('hybrid', $settings->operating_mode);
    }

    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'NexISP Books Tenant '.uniqid(),
            'slug' => 'books-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $actor = User::create([
            'name' => 'Books Billing User',
            'email' => 'books-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
        $actor->organizations()->attach($organization->id, [
            'role' => 'billing',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customer = NexispCustomer::create([
            'organization_id' => $organization->id,
            'customer_number' => 'NXC-'.uniqid(),
            'name' => 'Books Test Customer',
            'phone' => '+254700123456',
            'status' => 'active',
        ]);

        return [$actor, $organization, $customer];
    }
}
