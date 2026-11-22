<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'max:120'],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->with('actor:id,name,email')
            ->orderByDesc('id');

        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (isset($validated['actor_user_id'])) {
            $query->where('actor_user_id', $validated['actor_user_id']);
        }

        $limit = $validated['limit'] ?? 50;
        $logs = $query->limit($limit)->get();

        return response()->json([
            'data' => $logs->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                ] : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
