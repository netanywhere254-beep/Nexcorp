<?php

namespace App\Services;

use App\Models\GroupAuthority;
use App\Models\User;

class TraceReviewerCountryScope
{
    public function countriesFor(User $user): array
    {
        return GroupAuthority::query()
            ->where('user_id', $user->id)
            ->whereIn('authority', ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'])
            ->where('scope_type', 'country')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('scope_key')
            ->map(fn ($country) => strtoupper((string) $country))
            ->filter(fn ($country) => preg_match('/^[A-Z]{2}$/', $country) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
