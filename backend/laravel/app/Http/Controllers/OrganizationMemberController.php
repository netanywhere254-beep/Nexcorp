<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OrganizationMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrganizationMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $members = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email,status')
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->get()
            ->map(fn (OrganizationMember $member) => $this->payload($member));

        return response()->json(['data' => $members]);
    }

    public function show(Request $request, string $member): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $record = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email,status')
            ->findOrFail($member);

        return response()->json(['member' => $this->payload($record)]);
    }

    public function update(Request $request, string $member): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageMembers', $organization);

        $record = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email,status')
            ->findOrFail($member);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(OrganizationMember::ASSIGNABLE_ROLES)],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
        ]);

        abort_if(empty($validated), 422, 'No supported changes were provided.');

        $actor = $request->user();
        abort_if($actor->id === $record->user_id, 422, 'You cannot modify your own membership through this endpoint.');

        if ($record->role === 'owner') {
            abort(403, 'Owner memberships cannot be modified through this endpoint.');
        }

        if (array_key_exists('role', $validated)) {
            $newRole = $validated['role'];
            if ($newRole === 'admin' && ! Gate::forUser($actor)->allows('update', $organization)) {
                // OrganizationPolicy::update allows owner/admin; we need owner-only for admin assignment.
            }
            // Only owners can assign the admin role.
            if ($newRole === 'admin') {
                $isOwner = $organization->members()
                    ->where('user_id', $actor->id)
                    ->where('status', 'active')
                    ->where('role', 'owner')
                    ->exists();
                abort_unless($isOwner, 403, 'Only an organization owner can assign the admin role.');
            }
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== 'active') {
            // Prevent suspending the last active owner - owners can't be modified here anyway, but keep guard.
            if ($record->role === 'owner') {
                $activeOwners = OrganizationMember::query()
                    ->where('organization_id', $organization->id)
                    ->where('role', 'owner')
                    ->where('status', 'active')
                    ->count();
                abort_if($activeOwners <= 1, 422, 'The organization must retain at least one active owner.');
            }
        }

        $before = ['role' => $record->role, 'status' => $record->status];
        $record->fill($validated)->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'organization.member_updated',
            'subject_type' => OrganizationMember::class,
            'subject_id' => (string) $record->id,
            'metadata' => [
                'member_user_id' => $record->user_id,
                'before' => $before,
                'after' => ['role' => $record->role, 'status' => $record->status],
            ],
        ]);

        return response()->json(['member' => $this->payload($record->fresh('user:id,name,email,status'))]);
    }

    private function payload(OrganizationMember $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'role' => $member->role,
            'status' => $member->status,
            'joined_at' => $member->joined_at?->toIso8601String(),
            'user' => $member->user ? [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'status' => $member->user->status,
            ] : null,
        ];
    }
}
