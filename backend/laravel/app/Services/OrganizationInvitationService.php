<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationInvitationService
{
    public function findByToken(string $token): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()
            ->with('organization')
            ->where('token_hash', $this->hashToken($token))
            ->first();

        abort_unless($invitation, 404, 'This invitation could not be found.');

        return $invitation;
    }

    public function accept(string $token, User $user): OrganizationInvitation
    {
        try {
            return DB::transaction(function () use ($token, $user) {
                $invitation = $this->lockInvitation($token);

                $this->assertInvitationCanBeUsedBy($invitation, $user);

                $organization = Organization::query()
                    ->lockForUpdate()
                    ->find($invitation->organization_id);

                abort_unless(
                    $organization && $organization->status === 'active',
                    409,
                    'The organization is not accepting new members.'
                );

                $alreadyMember = OrganizationMember::query()
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->exists();

                abort_if(
                    $alreadyMember,
                    409,
                    'You already have a membership for this organization.'
                );

                OrganizationMember::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                $invitation->forceFill([
                    'status' => OrganizationInvitation::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->id,
                ])->save();

                AuditLog::create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $user->id,
                    'action' => 'organization.invitation_accepted',
                    'subject_type' => OrganizationInvitation::class,
                    'subject_id' => (string) $invitation->id,
                    'metadata' => [
                        'invitation_id' => $invitation->id,
                        'role' => $invitation->role,
                    ],
                ]);

                return $invitation->fresh(['organization']);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'You already have a membership for this organization.');
        }
    }

    public function decline(string $token, User $user): OrganizationInvitation
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = $this->lockInvitation($token);

            $this->assertInvitationCanBeUsedBy($invitation, $user);

            $invitation->forceFill([
                'status' => OrganizationInvitation::STATUS_DECLINED,
                'declined_at' => now(),
            ])->save();

            AuditLog::create([
                'organization_id' => $invitation->organization_id,
                'actor_user_id' => $user->id,
                'action' => 'organization.invitation_declined',
                'subject_type' => OrganizationInvitation::class,
                'subject_id' => (string) $invitation->id,
                'metadata' => [
                    'invitation_id' => $invitation->id,
                    'role' => $invitation->role,
                ],
            ]);

            return $invitation->fresh(['organization']);
        });
    }

    private function lockInvitation(string $token): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', $this->hashToken($token))
            ->lockForUpdate()
            ->first();

        abort_unless($invitation, 404, 'This invitation could not be found.');

        abort_unless(
            $invitation->status === OrganizationInvitation::STATUS_PENDING,
            409,
            'This invitation is no longer available.'
        );

        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');

        return $invitation;
    }

    private function assertInvitationCanBeUsedBy(OrganizationInvitation $invitation, User $user): void
    {
        abort_unless($user->status === 'active', 403, 'An active account is required.');
        abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email address before using an invitation.');
        abort_unless(
            Str::lower($invitation->email) === Str::lower($user->email),
            403,
            'This invitation was issued to a different email address.'
        );
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
