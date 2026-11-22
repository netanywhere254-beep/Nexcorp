<?php

namespace App\\Services\\Nexisp;

use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;

/**
 * Read-only, tenant- and currency-scoped financial statements built from posted journals.
 * Amounts are handled as integer minor units; floating-point arithmetic is avoided.
 */
class FinancialReportingService
{
    public function trialBalance(int $organizationId, string $from, string $to, string $currency = 'KES'): Collection
    {
        $this->assertDateRange($from, $to);

        return $this->balances($organizationId, $from, $to, $currency)
            ->map(fn (object $row) => $this->withNetBalance($row));
    }

    public function incomeStatement(int $organizationId, string $from, string $to, string $currency = 'KES'): array
    {
        $this->assertDateRange($from, $to);
        $rows = $this->balances($organizationId, $from, $to, $currency)
            ->whereIn('type', ['revenue', 'income', 'expense'])
            ->values();

        $revenue = 0;
        $expenses = 0;

        foreach ($rows as $row) {
            $debitMinusCredit = $this->minorUnits($row->debits) - $this->minorUnits($row->credits);
            if (in_array($row->type, ['revenue', 'income'], true)) {
                $revenue -= $debitMinusCredit;
            } else {
                $expenses += $debitMinusCredit;
            }
        }

        return [
            'organization_id' => $organizationId,
            'currency' => strtoupper($currency),
            'from' => $from,
            'to' => $to,
            'revenue' => $this->decimalFromMinor($revenue),
            'expenses' => $this->decimalFromMinor($expenses),
            'net_profit' => $this->decimalFromMinor($revenue - $expenses),
            'accounts' => $rows->map(fn (object $row) => $this->withNetBalance($row)),
        ];
    }

    public function balanceSheet(int $organizationId, string $asAt, string $currency = 'KES'): array
    {
        $this->assertDateRange($asAt, $asAt);
        $rows = $this->balances($organizationId, null, $asAt, $currency)->values();
        $balanceSheetRows = $rows
            ->whereIn('type', ['asset', 'liability', 'equity'])
            ->values();

        $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0];
        foreach ($balanceSheetRows as $row) {
            $debitMinusCredit = $this->minorUnits($row->debits) - $this->minorUnits($row->credits);
            $type = $row->type;
            $totals[$type] += $type === 'asset' ? $debitMinusCredit : -$debitMinusCredit;
        }

        // Until year-end closing journals are posted, current-period earnings
        // must appear in equity for the balance sheet to remain complete.
        $incomeRows = $rows->whereIn('type', ['revenue', 'income', 'expense']);
        $currentEarnings = 0;
        foreach ($incomeRows as $row) {
            $debitMinusCredit = $this->minorUnits($row->debits) - $this->minorUnits($row->credits);
            $currentEarnings -= $debitMinusCredit;
        }

        return [
            'organization_id' => $organizationId,
            'currency' => strtoupper($currency),
            'as_at' => $asAt,
            'assets' => $this->decimalFromMinor($totals['asset']),
            'liabilities' => $this->decimalFromMinor($totals['liability']),
            'equity' => $this->decimalFromMinor($totals['equity']),
            'current_earnings' => $this->decimalFromMinor($currentEarnings),
            'total_equity' => $this->decimalFromMinor($totals['equity'] + $currentEarnings),
            'liabilities_and_equity' => $this->decimalFromMinor($totals['liability'] + $totals['equity'] + $currentEarnings),
            'accounts' => $balanceSheetRows->map(fn (object $row) => $this->withNetBalance($row)),
        ];
    }

    private function balances(int $organizationId, ?string $from, string $to, string $currency): Collection
    {
        $currency = strtoupper($currency);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A valid three-letter ISO currency code is required.');
        }

        $query = DB::table('nexisp_accounts as accounts')
            ->leftJoin('nexisp_journal_lines as lines', 'lines.account_id', '=', 'accounts.id')
            ->leftJoin('nexisp_journal_entries as entries', function ($join) use ($organizationId, $from, $to, $currency) {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->where('entries.organization_id', '=', $organizationId)
                    ->where('entries.status', '=', 'posted')
                    ->where('entries.currency', '=', $currency)
                    ->whereDate('entries.entry_date', '<=', $to);
                if ($from !== null) {
                    $join->whereDate('entries.entry_date', '>=', $from);
                }
            })
            ->where('accounts.organization_id', $organizationId)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->select([
                'accounts.id as account_id',
                'accounts.code',
                'accounts.name',
                'accounts.type',
                'accounts.normal_balance',
            ])
            ->selectRaw('COALESCE(SUM(CASE WHEN entries.id IS NOT NULL THEN lines.debit ELSE 0 END), 0) as debits')
            ->selectRaw('COALESCE(SUM(CASE WHEN entries.id IS NOT NULL THEN lines.credit ELSE 0 END), 0) as credits');

        return $query->get();
    }

    private function withNetBalance(object $row): array
    {
        $debits = $this->minorUnits($row->debits);
        $credits = $this->minorUnits($row->credits);
        $debitNormal = $row->normal_balance === 'debit';
        $net = $debitNormal ? $debits - $credits : $credits - $debits;

        return [
            'account_id' => (int) $row->account_id,
            'code' => $row->code,
            'name' => $row->name,
            'type' => $row->type,
            'debits' => $this->decimalFromMinor($debits),
            'credits' => $this->decimalFromMinor($credits),
            'balance' => $this->decimalFromMinor($net),
        ];
    }

    private function assertDateRange(string $from, string $to): void
    {
        $start = strtotime($from);
        $end = strtotime($to);
        if ($start === false || $end === false || date('Y-m-d', $start) !== $from
            || date('Y-m-d', $end) !== $to || $start > $end) {
            throw new InvalidArgumentException('A valid ISO date range is required.');
        }
    }

    private function minorUnits(string|int|float $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Database returned an invalid monetary amount.');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        return $negative ? -$minor : $minor;
    }

    private function decimalFromMinor(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);
        return $sign . intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
