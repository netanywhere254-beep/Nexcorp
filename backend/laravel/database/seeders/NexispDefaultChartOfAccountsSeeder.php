<?php

namespace Database\Seeders;

use App\Models\NexispAccount;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class NexispDefaultChartOfAccountsSeeder extends Seeder
{
    /**
     * Install an ISP-ready chart of accounts for every existing organization.
     * Re-running this seeder is safe: organization + account code is unique.
     */
    public function run(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'normal_balance' => 'debit', 'parent' => null],
            ['code' => '1100', 'name' => 'Cash and Mobile Money', 'type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1000'],
            ['code' => '1110', 'name' => 'Bank Accounts', 'type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1000'],
            ['code' => '1120', 'name' => 'M-Pesa / Mobile Money Clearing', 'type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1000'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1000'],
            ['code' => '1300', 'name' => 'Network Equipment', 'type' => 'asset', 'normal_balance' => 'debit', 'parent' => '1000'],
            ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'normal_balance' => 'credit', 'parent' => null],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'parent' => '2000'],
            ['code' => '2200', 'name' => 'Tax Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'parent' => '2000'],
            ['code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity', 'normal_balance' => 'credit', 'parent' => null],
            ['code' => '4000', 'name' => 'Operating Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'parent' => null],
            ['code' => '4100', 'name' => 'Internet Subscription Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '4000'],
            ['code' => '4200', 'name' => 'Installation and Equipment Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'parent' => '4000'],
            ['code' => '5000', 'name' => 'Operating Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'parent' => null],
            ['code' => '5100', 'name' => 'Bandwidth and Upstream Costs', 'type' => 'expense', 'normal_balance' => 'debit', 'parent' => '5000'],
            ['code' => '5200', 'name' => 'Power and Site Utilities', 'type' => 'expense', 'normal_balance' => 'debit', 'parent' => '5000'],
            ['code' => '5300', 'name' => 'Repairs and Maintenance', 'type' => 'expense', 'normal_balance' => 'debit', 'parent' => '5000'],
            ['code' => '5400', 'name' => 'Salaries and Administration', 'type' => 'expense', 'normal_balance' => 'debit', 'parent' => '5000'],
        ];

        Organization::query()->select('id')->chunkById(100, function ($organizations) use ($accounts): void {
            foreach ($organizations as $organization) {
                $idsByCode = [];

                foreach ($accounts as $account) {
                    $parentId = $account['parent'] === null ? null : ($idsByCode[$account['parent']] ?? null);

                    $record = NexispAccount::query()->firstOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'code' => $account['code'],
                        ],
                        [
                            'parent_id' => $parentId,
                            'name' => $account['name'],
                            'type' => $account['type'],
                            'normal_balance' => $account['normal_balance'],
                            'is_system' => true,
                            'is_active' => true,
                        ]
                    );

                    $idsByCode[$account['code']] = $record->id;
                }
            }
        });
    }
}
