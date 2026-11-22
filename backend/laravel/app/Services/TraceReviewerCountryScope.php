<?php

namespace App\Services;

use App\Models\GroupAuthority;
use App\Models\User;

class TraceReviewerCountryScope
{
    public function countriesFor(User $user): array
    {
        return $this->normalise($this->activeCountryQuery($user)->pluck('scope_key')->all());
    }

    /**
     * Lock active country appointments while a sensitive decision is committed.
     * Revocation updates the same GroupAuthority rows, so it must wait until
     * this transaction completes (or this transaction observes the revocation).
     */
    public function lockCountriesFor(User $user): array
    {
        return $this->normalise(
            $this->activeCountryQuery($user)->lockForUpdate()->pluck('scope_key')->all()
        );
    }

    private function activeCountryQuery(User $user)
    {
        return GroupAuthority::query()
            ->where('user_id', $user->id)
            ->whereIn('authority', ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'])
            ->where('scope_type', 'country')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    private function normalise(array $countries): array
    {
        return collect($countries)
            ->map(fn ($country) => strtoupper((string) $country))
            ->filter(fn ($country) => preg_match('/^[A-Z]{2}$/', $country) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
