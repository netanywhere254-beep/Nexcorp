<?php

namespace App\Services\Nexisp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, tenant-scoped financial statements built only from posted journals.
 * Amounts remain decimal strings from the database; no float arithmetic is used.
 */
class FinancialReportingService
{
    public function trialBalance(int $organizationId, string $from, string $to): Collection
    {
        $this->assertDateRange($from, $to);

        return $this->balances($organizationId, $from, $to)
            ->map(fn (object $row) => $this->withNetBalance($row));
    }

    public function incomeStatement(int $organizationId, string $from, string $to): array
    {
        $this->assertDateRange($from, $to);
        $rows = $this->balances($organizationId, $from, $to)
            ->whereIn('type', ['revenue', 'income', 'expense'])
            ->values();

        $revenue = '0.00';
        $expenses = '0.00';

        foreach ($rows as $row) {
            $net = $this->minorUnits($row->debits) - $this->minorUnits($row->credits);
            if (in_array($row->type, ['revenue', 'income'], true)) {
                $revenue = $this->decimalFromMinor($this->minorUnits($revenue) - $net);
            } else {
                $expenses = $this->decimalFromMinor($this->minorUnits($expenses) + $net);
            }
        }

        return [
            'organization_id' => $organizationId,
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_profit' => $this->decimalFromMinor($this->minorUnits($revenue) - $this->minorUnits($expenses)),
            'accounts' => $rows->map(fn (object $row) => $this->withNetBalance($row)),
        ];
    }

    public function balanceSheet(int $organizationId, string $asAt): array
    {
        $this->assertDateRange($asAt, $asAt);
        $rows = $this->balances($organizationId, null, $asAt)
            ->whereIn('type', ['asset', 'liability', 'equity'])
            ->values();

        $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0];
        foreach ($rows as $row) {
            $debitMinusCredit = $this->minorUnits($row->debits) - $this->minorUnits($row->credits);
            $type = $row->type;
            $totals[$type] += $type === 'asset' ? $debitMinusCredit : -$debitMinusCredit;
        }

        return [
            'organization_id' => $organizationId,
            'as_at' => $asAt,
            'assets' => $this->decimalFromMinor($totals['asset']),
            'liabilities' => $this->decimalFromMinor($totals['liability']),
            'equity' => $this->decimalFromMinor($totals['equity']),
            'accounts' => $rows->map(fn (object $row) => $this->withNetBalance($row)),
        ];
    }

    private function balances(int $organizationId, ?string $from, string $to): Collection
    {
        $query = DB::table('nexisp_accounts as accounts')
            ->leftJoin('nexisp_journal_lines as lines', 'lines.account_id', '=', 'accounts.id')
            ->leftJoin('nexisp_journal_entries as entries', function ($join) use ($organizationId, $from, $to) {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->where('entries.organization_id', '=', $organizationId)
                    ->where('entries.status', '=', 'posted')
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
        $debitNormal = in_array($row->normal_balance, ['debit'], true);
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
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $value)) {
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
