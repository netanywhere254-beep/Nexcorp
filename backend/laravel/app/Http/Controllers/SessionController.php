<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SessionController
{

    /**
     * Public registration creates a basic account only.
     *
     * Privileged roles and organization memberships are never accepted from
     * public input. Membership and role assignment must use authorized,
     * auditable invitation or administration workflows.
     */
    public function register(Request $request, RateLimiter $limiter): JsonResponse
    {
        $key = 'nexora-register:'.$request->ip();

        if ($limiter->tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many registration attempts. Please wait before trying again.',
                'retry_after_seconds' => $limiter->availableIn($key),
            ], 429);
        }

        $limiter->hit($key, 60);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'status' => 'active',
        ]);

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $user->id,
            'action' => 'user.registered',
            'subject_type' => User::class,
            'subject_id' => (string) $user->id,
            'metadata' => ['source' => 'public_registration'],
        ]);

        return response()->json([
            'message' => 'Account created. Organization access requires an invitation or authorized administrator onboarding.',
            'user' => $this->userPayload($user),
        ], 201);
    }

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
            'status' => 'active',
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
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
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
            ->wherePivot('status', 'active')
            ->where('organizations.status', 'active')
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

    private function userPayload(User $user): array
    {

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'organizations' => $user->organizations()
                ->wherePivot('status', 'active')
                ->where('organizations.status', 'active')
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
