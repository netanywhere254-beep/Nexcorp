<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController
{
    public function login(Request $request, RateLimiter $limiter): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $key = 'nexora-login:'.Str::lower($validated['email']).'|'.$request->ip();

        if ($limiter->tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please wait before trying again.',
                'retry_after_seconds' => $limiter->availableIn($key),
            ], 429);
        }

        if (! Auth::attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ], (bool) ($validated['remember'] ?? false))) {
            $limiter->hit($key, 60);

            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are incorrect.'],
            ]);
        }

        $limiter->clear($key);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Signed in successfully.',
            'user' => $this->userPayload($request),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request),
            'active_organization_id' => $request->session()->get('active_organization_id'),
        ]);
    }

    public function selectOrganization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $organization = $user->organizations()
            ->whereKey($validated['organization_id'])
            ->first();

        if (! $organization) {
            return response()->json([
                'message' => 'You are not a member of that organization.',
            ], 403);
        }

        $request->session()->put('active_organization_id', $organization->id);

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'action' => 'organization.selected',
            'subject_type' => get_class($organization),
            'subject_id' => (string) $organization->id,
            'metadata' => ['source' => 'web_session'],
        ]);

        return response()->json([
            'message' => 'Active organization changed.',
            'active_organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $organization->pivot->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->forget('active_organization_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    private function userPayload(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'organizations' => $user->organizations()
                ->get(['organizations.id', 'organizations.name', 'organizations.slug', 'organizations.type'])
                ->map(fn ($organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'type' => $organization->type,
                    'role' => $organization->pivot->role,
                    'membership_status' => $organization->pivot->status,
                ])
                ->values(),
        ];
    }
}
