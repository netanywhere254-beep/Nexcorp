<?php

namespace App\Services;

use App\Models\TraceCase;
use App\Models\TraceCountryAccessGrant;
use App\Models\User;

class TraceCountryAccess
{
    private const ROLE_PERMISSIONS = [
        'authority' => ['cases.review', 'cases.read', 'documents.read', 'authority.confirmation.submit'],
        'professional_investigator' => ['cases.read', 'cases.match.submit', 'documents.read'],
        'volunteer' => ['public_tasks.view', 'public_tasks.submit'],
    ];

    public function allows(User $user, string $permission, TraceCase $case): bool
    {
        return TraceCountryAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('country_code', strtoupper((string) $case->incident_country_code))
            ->whereJsonContains('permissions', $permission)
            ->whereHas('application', function ($query) {
                $query->where('status', 'approved')
                    ->whereColumn('trace_access_applications.user_id', 'trace_country_access_grants.user_id')
                    ->whereColumn('trace_access_applications.requested_role', 'trace_country_access_grants.access_role')
                    ->whereColumn('trace_access_applications.country_code', 'trace_country_access_grants.country_code');
            })
            ->where(function ($query) use ($case) {
                $query->whereNull('region_code')
                    ->orWhere('region_code', (string) $case->incident_region_code);
            })
            ->get()
            ->contains(function (TraceCountryAccessGrant $grant) use ($permission) {
                return in_array($permission, self::ROLE_PERMISSIONS[$grant->access_role] ?? [], true);
            });
    }

    public function publishedCasesFor(User $user)
    {
        $grantScopes = TraceCountryAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->whereJsonContains('permissions', 'cases.read')
            ->whereHas('application', fn ($query) => $query->where('status', 'approved')
                ->whereColumn('trace_access_applications.user_id', 'trace_country_access_grants.user_id')
                ->whereColumn('trace_access_applications.requested_role', 'trace_country_access_grants.access_role')
                ->whereColumn('trace_access_applications.country_code', 'trace_country_access_grants.country_code'))
            ->get()
            ->filter(fn (TraceCountryAccessGrant $grant) =>
                in_array('cases.read', self::ROLE_PERMISSIONS[$grant->access_role] ?? [], true)
            );

        if ($grantScopes->isEmpty()) {
            return TraceCase::query()->whereRaw('1 = 0');
        }

        return TraceCase::query()
            ->where('publication_status', 'published')
            ->where(function ($query) use ($grantScopes) {
                foreach ($grantScopes as $grant) {
                    $query->orWhere(function ($scope) use ($grant) {
                        $scope->where('incident_country_code', $grant->country_code);
                        if ($grant->region_code !== null) {
                            $scope->where('incident_region_code', $grant->region_code);
                        }
                    });
                }
            });
    }
}
