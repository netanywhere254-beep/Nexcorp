<?php

namespace App\Services\Nexisp;

use App\Models\NexispAccount;
use App\Models\NexispJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalPostingService
{
    /**
     * Post a balanced journal entry. Amounts are accepted as decimal strings
     * and compared in integer minor units to avoid floating-point drift.
     *
     * @param array<int, array{account_id:int, debit?:string|int, credit?:string|int, memo?:?string}> $lines
     */
    public function post(
        int $organizationId,
        string $entryNumber,
        string $entryDate,
        string $description,
        array $lines,
        ?int $actorUserId = null,
        string $currency = 'KES',
        ?int $fiscalPeriodId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): NexispJournalEntry {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal entry requires at least two lines.']);
        }

        return DB::transaction(function () use (
            $organizationId, $entryNumber, $entryDate, $description, $lines,
            $actorUserId, $currency, $fiscalPeriodId, $sourceType, $sourceId
        ) {
            $period = null;
            if ($fiscalPeriodId !== null) {
                $period = DB::table('nexisp_fiscal_periods')
                    ->where('id', $fiscalPeriodId)
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->first();

                if (!$period || $period->status !== 'open'
                    || $entryDate < $period->starts_on || $entryDate > $period->ends_on) {
                    throw ValidationException::withMessages(['fiscal_period_id' => 'The fiscal period is not open for this entry date.']);
                }
            }

            $accountIds = array_values(array_unique(array_map(
                fn (array $line) => (int) ($line['account_id'] ?? 0),
                $lines
            )));

            $accounts = NexispAccount::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereIn('id', $accountIds)
                ->get()
                ->keyBy('id');

            if ($accounts->count() !== count($accountIds) || in_array(0, $accountIds, true)) {
                throw ValidationException::withMessages(['lines' => 'Every account must be active and belong to this organization.']);
            }

            $debitTotal = 0;
            $creditTotal = 0;
            $normalised = [];

            foreach ($lines as $index => $line) {
                $debit = $this->minorUnits($line['debit'] ?? '0');
                $credit = $this->minorUnits($line['credit'] ?? '0');

                if (($debit > 0) === ($credit > 0)) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Each line must contain either a debit or a credit, not both.']);
                }

                $debitTotal += $debit;
                $creditTotal += $credit;
                $normalised[] = [
                    'account_id' => (int) $line['account_id'],
                    'memo' => $line['memo'] ?? null,
                    'debit' => number_format($debit / 100, 2, '.', ''),
                    'credit' => number_format($credit / 100, 2, '.', ''),
                ];
            }

            if ($debitTotal <= 0 || $debitTotal !== $creditTotal) {
                throw ValidationException::withMessages(['lines' => 'Total debits must equal total credits and be greater than zero.']);
            }

            $entry = NexispJournalEntry::query()->create([
                'organization_id' => $organizationId,
                'fiscal_period_id' => $fiscalPeriodId,
                'created_by' => $actorUserId,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'currency' => strtoupper($currency),
                'status' => 'draft',
            ]);

            $entry->lines()->createMany($normalised);
            $entry->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actorUserId,
            ])->save();

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => 'nexisp.accounting.journal.posted',
                'subject_type' => NexispJournalEntry::class,
                'subject_id' => (string) $entry->id,
                'metadata' => json_encode([
                    'entry_number' => $entryNumber,
                    'currency' => strtoupper($currency),
                    'total_minor_units' => $debitTotal,
                    'line_count' => count($normalised),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $entry->load('lines.account');
        });
    }

    private function minorUnits(string|int $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['lines' => 'Amounts must be non-negative decimal values with no more than two decimal places.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
