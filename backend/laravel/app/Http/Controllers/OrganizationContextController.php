<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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

    public function update(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'country_code' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        abort_if(empty($validated), 422, 'No supported changes were provided.');

        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        $before = $organization->only(array_keys($validated));
        $organization->fill($validated)->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'organization.profile_updated',
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => (string) $organization->id,
            'metadata' => ['before' => $before, 'after' => $organization->only(array_keys($validated))],
        ]);

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'type' => $organization->type,
                'country_code' => $organization->country_code,
                'timezone' => $organization->timezone,
            ],
        ]);
    }
}
