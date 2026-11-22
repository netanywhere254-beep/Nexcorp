<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\OrganizationInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationInvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([
                OrganizationInvitation::STATUS_PENDING,
                OrganizationInvitation::STATUS_ACCEPTED,
                OrganizationInvitation::STATUS_DECLINED,
                OrganizationInvitation::STATUS_REVOKED,
                OrganizationInvitation::STATUS_EXPIRED,
            ])],
        ]);

        $invitations = OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('status', $validated['status'] ?? OrganizationInvitation::STATUS_PENDING)
            ->when(
                ($validated['status'] ?? OrganizationInvitation::STATUS_PENDING) === OrganizationInvitation::STATUS_PENDING,
                fn ($query) => $query->where('expires_at', '>', now())
            )
            ->latest()
            ->get()
            ->map(fn (OrganizationInvitation $invitation) => $this->payload($invitation));

        return response()->json(['data' => $invitations]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', 'string', Rule::in(OrganizationInvitation::INVITABLE_ROLES)],
        ]);

        $email = Str::lower($validated['email']);
        $role = $validated['role'];

        // The policy enforces both membership authority and role escalation:
        // an organisation administrator cannot mint another administrator,
        // and the owner role can never be granted by invitation.
        Gate::authorize('invite', [$organization, $role]);

        $plainToken = bin2hex(random_bytes(32));

        $invitation = DB::transaction(function () use ($organization, $request, $email, $role, $plainToken) {
            // Serialise invitation creation per tenant so concurrent requests
            // cannot create duplicate pending invitations for one address.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $alreadyMember = OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                ->exists();

            abort_if($alreadyMember, 409, 'That email already belongs to an organization member.');

            OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->where('expires_at', '<=', now())
                ->update(['status' => OrganizationInvitation::STATUS_EXPIRED]);

            $pendingExists = OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->exists();

            abort_if($pendingExists, 409, 'A pending invitation already exists for that email.');

            $invitation = OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'invited_by_user_id' => $request->user()->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $plainToken),
                'status' => OrganizationInvitation::STATUS_PENDING,
                'expires_at' => now()->addDays((int) config('nexora.invitation_ttl_days', 7)),
            ]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'action' => 'organization.invitation_sent',
                'subject_type' => OrganizationInvitation::class,
                'subject_id' => (string) $invitation->id,
                'metadata' => [
                    'invitation_id' => $invitation->id,
                    'role' => $invitation->role,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ],
            ]);

            return $invitation;
        });

        $emailSent = true;

        try {
            Notification::route('mail', $email)->notify(
                new OrganizationInvitationNotification($invitation->load('organization'), $plainToken)
            );
        } catch (\Throwable $exception) {
            report($exception);
            $emailSent = false;
        }

        $payload = $this->payload($invitation);
        $payload['email_sent'] = $emailSent;

        // The raw token is shown only when delivery failed, so an authorized
        // administrator can pass it to the invitee through a safe channel.
        if (! $emailSent) {
            $payload['acceptance_token'] = $plainToken;
            $payload['acceptance_url'] = url('/auth/invitations/preview/'.$plainToken);
        }

        return response()->json([
            'message' => $emailSent
                ? 'Invitation created and emailed to the invitee.'
                : 'Invitation created, but email delivery failed. Share the one-time token securely.',
            'invitation' => $payload,
        ], 201);
    }

    public function destroy(Request $request, string $invitation): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageMembers', $organization);

        // Resolve inside the server-verified tenant instead of using implicit
        // route binding, so an ID from another organization is simply not found.
        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->find($invitation);

        abort_unless($invitation, 404);

        abort_unless(
            $invitation->status === OrganizationInvitation::STATUS_PENDING,
            409,
            'Only pending invitations can be revoked.'
        );

        $invitation->forceFill([
            'status' => OrganizationInvitation::STATUS_REVOKED,
            'revoked_at' => now(),
        ])->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'organization.invitation_revoked',
            'subject_type' => OrganizationInvitation::class,
            'subject_id' => (string) $invitation->id,
            'metadata' => [
                'invitation_id' => $invitation->id,
                'role' => $invitation->role,
            ],
        ]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function pending(Request $request): JsonResponse
    {
        $invitations = OrganizationInvitation::query()
            ->with('organization')
            ->where('email', Str::lower($request->user()->email))
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->map(fn (OrganizationInvitation $invitation) => $this->payload($invitation));

        return response()->json(['data' => $invitations]);
    }

    public function accept(Request $request, OrganizationInvitationService $service): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $invitation = $service->accept($validated['token'], $request->user());

        return response()->json([
            'message' => 'Invitation accepted. You can now select this organization.',
            'invitation' => $this->payload($invitation),
            'organization' => [
                'id' => $invitation->organization->id,
                'name' => $invitation->organization->name,
                'slug' => $invitation->organization->slug,
                'role' => $invitation->role,
            ],
        ]);
    }

    public function decline(Request $request, OrganizationInvitationService $service): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        $invitation = $service->decline($validated['token'], $request->user());

        return response()->json([
            'message' => 'Invitation declined.',
            'invitation' => $this->payload($invitation),
        ]);
    }

    public function preview(string $token, OrganizationInvitationService $service): JsonResponse
    {
        $invitation = $service->findByToken($token);

        return response()->json([
            'invitation' => $this->payload($invitation),
            'usable' => $invitation->isUsable(),
        ]);
    }

    private function payload(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'declined_at' => $invitation->declined_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'invited_by_user_id' => $invitation->invited_by_user_id,
            'organization' => $invitation->organization ? [
                'id' => $invitation->organization->id,
                'name' => $invitation->organization->name,
                'slug' => $invitation->organization->slug,
            ] : null,
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
