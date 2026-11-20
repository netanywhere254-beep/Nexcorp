<?php

namespace App\\Services;

use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;

class CaseReferenceNumber
{
    /**
     * Separate, monotonically increasing yearly sequences for the Nexora Group
     * and Loopza Trace & Find systems. The caller must persist the returned
     * reference in the same business workflow that creates its case.
     */
    public function next(string $system, ?int $year = null): string
    {
        $systems = [
            'nexora' => 'NEX',
            'loopza' => 'LTF',
        ];

        if (! array_key_exists($system, $systems)) {
            throw new InvalidArgumentException('Unsupported case registration system.');
        }

        $year ??= (int) now()->format('Y');
        if ($year < 2000 || $year > 9999) {
            throw new InvalidArgumentException('Invalid registration year.');
        }

        return DB::transaction(function () use ($system, $year, $systems): string {
            DB::table('case_reference_sequences')->insertOrIgnore([
                'system' => $system,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('case_reference_sequences')
                ->where('system', $system)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new \\RuntimeException('Unable to initialise the case reference sequence.');
            }

            $next = ((int) $sequence->last_number) + 1;

            DB::table('case_reference_sequences')
                ->where('system', $system)
                ->where('year', $year)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return sprintf('%s-%04d-%06d', $systems[$system], $year, $next);
        }, 5);
    }
}
