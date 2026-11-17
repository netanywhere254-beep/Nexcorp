<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationContextController
{
    public function show(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        $user = $request->user();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'type' => $organization->type,
                'country_code' => $organization->country_code,
                'timezone' => $organization->timezone,
            ],
            'membership' => [
                'role' => $organization->pivot->role,
                'status' => $organization->pivot->status,
            ],
            'permissions' => [
                'view' => Gate::forUser($user)->allows('view', $organization),
                'update' => Gate::forUser($user)->allows('update', $organization),
                'manage_members' => Gate::forUser($user)->allows('manageMembers', $organization),
                'manage_billing' => Gate::forUser($user)->allows('manageBilling', $organization),
                'manage_network' => Gate::forUser($user)->allows('manageNetwork', $organization),
            ],
        ]);
    }
}
