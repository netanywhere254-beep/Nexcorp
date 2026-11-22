<?php

namespace Tests\Feature;

use App\Models\NexispAccount;
use App\Models\Organization;
use Database\Seeders\NexispDefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispDefaultChartOfAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_installs_an_isp_chart_once_per_organization_and_is_safe_to_rerun(): void
    {
        $first = Organization::create([
            'name' => 'NetPulse WiFi',
            'slug' => 'netpulse-wifi',
            'type' => 'isp',
            'status' => 'active',
        ]);
        $second = Organization::create([
            'name' => 'Other ISP',
            'slug' => 'other-isp',
            'type' => 'isp',
            'status' => 'active',
        ]);

        $seeder = app(NexispDefaultChartOfAccountsSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertSame(18, NexispAccount::query()->where('organization_id', $first->id)->count());
        $this->assertSame(18, NexispAccount::query()->where('organization_id', $second->id)->count());

        $receivable = NexispAccount::query()
            ->where('organization_id', $first->id)
            ->where('code', '1200')
            ->firstOrFail();
        $assets = NexispAccount::query()
            ->where('organization_id', $first->id)
            ->where('code', '1000')
            ->firstOrFail();

        $this->assertSame($assets->id, $receivable->parent_id);
        $this->assertTrue($receivable->is_system);
        $this->assertTrue($receivable->is_active);
    }

    public function test_reseeding_does_not_overwrite_a_tenant_customised_account(): void
    {
        $organization = Organization::create([
            'name' => 'NetPulse WiFi',
            'slug' => 'netpulse-wifi',
            'type' => 'isp',
            'status' => 'active',
        ]);

        NexispAccount::create([
            'organization_id' => $organization->id,
            'code' => '4100',
            'name' => 'Custom Internet Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'is_system' => true,
            'is_active' => false,
        ]);

        app(NexispDefaultChartOfAccountsSeeder::class)->run();

        $account = NexispAccount::query()
            ->where('organization_id', $organization->id)
            ->where('code', '4100')
            ->firstOrFail();

        $this->assertSame('Custom Internet Revenue', $account->name);
        $this->assertFalse($account->is_active);
        $this->assertSame(18, NexispAccount::query()->where('organization_id', $organization->id)->count());
    }
}
