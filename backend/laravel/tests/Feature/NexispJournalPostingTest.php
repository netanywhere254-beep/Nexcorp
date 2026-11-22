<?php

namespace Tests\Feature;

use App\Models\NexispAccount;
use App\Models\NexispJournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Nexisp\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NexispJournalPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_journal_posts_and_writes_audit_record(): void
    {
        [$organization, $user, $cash, $revenue] = $this->ledgerFixture();

        $entry = app(JournalPostingService::class)->post(
            $organization->id,
            'JE-000001',
            '2026-11-22',
            'Record monthly subscription revenue',
            [
                ['account_id' => $cash->id, 'debit' => '1500.00'],
                ['account_id' => $revenue->id, 'credit' => '1500.00'],
            ],
            $user->id,
        );

        $this->assertSame('posted', $entry->status);
        $this->assertCount(2, $entry->lines);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'action' => 'nexisp.accounting.journal.posted',
            'subject_id' => (string) $entry->id,
        ]);
    }

    public function test_unbalanced_journal_is_rejected_without_partial_records(): void
    {
        [$organization, $user, $cash, $revenue] = $this->ledgerFixture();

        try {
            app(JournalPostingService::class)->post(
                $organization->id,
                'JE-000002',
                '2026-11-22',
                'Invalid entry',
                [
                    ['account_id' => $cash->id, 'debit' => '100.00'],
                    ['account_id' => $revenue->id, 'credit' => '90.00'],
                ],
                $user->id,
            );
            $this->fail('Unbalanced journals must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('nexisp_journal_entries', 0);
            $this->assertDatabaseCount('nexisp_journal_lines', 0);
        }
    }

    public function test_account_from_another_tenant_cannot_be_used(): void
    {
        [$organization, $user, $cash] = $this->ledgerFixture();
        $otherOrganization = Organization::create([
            'name' => 'Other ISP',
            'slug' => 'other-isp',
            'type' => 'business',
            'status' => 'active',
        ]);
        $foreignAccount = NexispAccount::create([
            'organization_id' => $otherOrganization->id,
            'code' => '4000',
            'name' => 'Foreign revenue',
            'type' => 'income',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(JournalPostingService::class)->post(
            $organization->id,
            'JE-000003',
            '2026-11-22',
            'Cross-tenant attempt',
            [
                ['account_id' => $cash->id, 'debit' => '10.00'],
                ['account_id' => $foreignAccount->id, 'credit' => '10.00'],
            ],
            $user->id,
        );
    }

    private function ledgerFixture(): array
    {
        $organization = Organization::create([
            'name' => 'NetPulse WiFi',
            'slug' => 'netpulse-wifi',
            'type' => 'isp',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $cash = NexispAccount::create([
            'organization_id' => $organization->id,
            'code' => '1000',
            'name' => 'Cash and mobile money',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $revenue = NexispAccount::create([
            'organization_id' => $organization->id,
            'code' => '4000',
            'name' => 'Internet subscription revenue',
            'type' => 'income',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        return [$organization, $user, $cash, $revenue];
    }
}
