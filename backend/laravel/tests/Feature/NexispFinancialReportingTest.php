<?php

namespace Tests\Feature;

use App\Models\NexispAccount;
use App\Models\Organization;
use App\Services\Nexisp\FinancialReportingService;
use App\Services\Nexisp\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispFinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_statement_uses_only_posted_journals_from_the_requested_tenant(): void
    {
        $organization = Organization::create([
            'name' => 'NetPulse WiFi',
            'slug' => 'netpulse-wifi',
            'type' => 'isp',
            'status' => 'active',
        ]);
        $cash = $this->account($organization->id, '1000', 'Cash', 'asset', 'debit');
        $revenue = $this->account($organization->id, '4100', 'Internet Revenue', 'revenue', 'credit');
        $expense = $this->account($organization->id, '5100', 'Bandwidth Costs', 'expense', 'debit');

        $posting = app(JournalPostingService::class);
        $posting->post($organization->id, 'JE-REV-1', '2026-11-22', 'Subscription revenue', [
            ['account_id' => $cash->id, 'debit' => '1500.00'],
            ['account_id' => $revenue->id, 'credit' => '1500.00'],
        ]);
        $posting->post($organization->id, 'JE-EXP-1', '2026-11-22', 'Bandwidth expense', [
            ['account_id' => $expense->id, 'debit' => '200.00'],
            ['account_id' => $cash->id, 'credit' => '200.00'],
        ]);

        $other = Organization::create([
            'name' => 'Other ISP',
            'slug' => 'other-isp',
            'type' => 'isp',
            'status' => 'active',
        ]);
        $foreignCash = $this->account($other->id, '1000', 'Cash', 'asset', 'debit');
        $foreignRevenue = $this->account($other->id, '4100', 'Revenue', 'revenue', 'credit');
        $posting->post($other->id, 'JE-OTHER-1', '2026-11-22', 'Other tenant revenue', [
            ['account_id' => $foreignCash->id, 'debit' => '9000.00'],
            ['account_id' => $foreignRevenue->id, 'credit' => '9000.00'],
        ]);

        $report = app(FinancialReportingService::class)
            ->incomeStatement($organization->id, '2026-11-01', '2026-11-30');

        $this->assertSame('1500.00', $report['revenue']);
        $this->assertSame('200.00', $report['expenses']);
        $this->assertSame('1300.00', $report['net_profit']);
    }

    public function test_invalid_report_date_range_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(FinancialReportingService::class)
            ->trialBalance(1, '2026-12-01', '2026-11-01');
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
