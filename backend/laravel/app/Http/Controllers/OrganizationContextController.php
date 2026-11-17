<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationContextController extends Controller
{
    /**
     * Return only the organization context verified by RequireActiveOrganization.
     */
    public function show(Request $request)
    {
        $organization = $request->attributes->get('activeOrganization');

        abort_unless($organization, 403, 'A verified organization context is required.');

        Gate::authorize('view', $organization);

        $membership = $request->user()
            ->organizations()
            ->whereKey($organization->getKey())
            ->firstOrFail();

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $organization->getKey(),
                    'name' => $organization->name,
                ],
                'membership' => [
                    'role' => $membership->pivot->role,
                    'status' => $membership->pivot->status,
                ],
            ],
        ]);
    }
}
