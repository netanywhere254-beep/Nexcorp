<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EngineerAccessGrant;
use App\Models\EngineerSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class EngineerSessionService
{
    public const DEACTIVATE_COMMAND = 'GOLD MODE DEACTIVATE';

    /**
     * Create a short-lived, tenant-scoped engineer session.
     *
     * Authentication, MFA and the caller's explicit engineer authorization
     * must be enforced before this method is called. Membership is rechecked
     * here to prevent stale or cross-tenant context from creating a session.
     *
     * @return array{session: EngineerSession, token: string}
     */
    public function start(
        User $actor,
        Organization $organization,
        int $idleTimeoutSeconds = 600,
        int $maxDurationSeconds = 3600,
    ): array {
        if ($idleTimeoutSeconds < 30 || $maxDurationSeconds < $idleTimeoutSeconds || $maxDurationSeconds > 14400) {
            throw new RuntimeException('Engineer session timeout configuration is invalid.');
        }

        $plainToken = bin2hex(random_bytes(32));
        $now = now();

        $session = DB::transaction(function () use ($actor, $organization, $idleTimeoutSeconds, $maxDurationSeconds, $plainToken, $now) {
            // Serialize session creation per tenant to prevent simultaneous starts.
            $lockedOrganization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            if (! $lockedOrganization || $lockedOrganization->status !== 'active' || $actor->status !== 'active') {
                throw new RuntimeException('An active actor and organization are required.');
            }

            $membership = $actor->organizations()
                ->wherePivot('status', 'active')
                ->where('organizations.status', 'active')
                ->whereKey($lockedOrganization->getKey())
                ->exists();

            if (! $membership) {
                throw new RuntimeException('Active organization membership is required.');
            }

            if (! $this->hasActiveGrant($actor, $lockedOrganization, $now)) {
                throw new RuntimeException('A current, dual-approved engineer access grant is required.');
            }

            $expired = EngineerSession::query()
                ->where('organization_id', $lockedOrganization->id)
                ->where('actor_user_id', $actor->id)
                ->whereNull('revoked_at')
                ->where(function ($query) use ($now) {
                    $query->where('idle_expires_at', '<=', $now)
                        ->orWhere('absolute_expires_at', '<=', $now);
                })
                ->lockForUpdate()
                ->get();

            foreach ($expired as $oldSession) {
                $oldSession->forceFill([
                    'revoked_at' => $now,
                    'revocation_reason' => 'expired',
                ])->save();

                $this->audit($lockedOrganization, $actor, 'engineer.session_expired', $oldSession, ['reason' => 'expired']);
            }

            $existing = EngineerSession::query()
                ->where('organization_id', $lockedOrganization->id)
                ->where('actor_user_id', $actor->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new RuntimeException('An engineer session is already active for this actor and organization.');
            }

            $created = EngineerSession::create([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $lockedOrganization->id,
                'actor_user_id' => $actor->id,
                'token_hash' => hash('sha256', $plainToken),
                'started_at' => $now,
                'last_activity_at' => $now,
                'idle_expires_at' => $now->copy()->addSeconds($idleTimeoutSeconds),
                'absolute_expires_at' => $now->copy()->addSeconds($maxDurationSeconds),
            ]);

            $this->audit($lockedOrganization, $actor, 'engineer.session_started', $created, [
                'idle_timeout_seconds' => $idleTimeoutSeconds,
                'max_duration_seconds' => $maxDurationSeconds,
            ]);

            return $created;
        });

        // The raw bearer token is returned once; only its SHA-256 hash is persisted.
        return ['session' => $session, 'token' => $plainToken];
    }

    public function touch(User $actor, Organization $organization, string $token, int $idleTimeoutSeconds = 600): EngineerSession
    {
        if ($idleTimeoutSeconds < 30 || $idleTimeoutSeconds > 3600) {
            throw new RuntimeException('Engineer session idle timeout configuration is invalid.');
        }

        $outcome = DB::transaction(function () use ($actor, $organization, $token, $idleTimeoutSeconds) {
            $session = EngineerSession::query()
                ->where('organization_id', $organization->getKey())
                ->where('actor_user_id', $actor->getKey())
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return ['session' => null, 'error' => 'No matching active engineer session.'];
            }

            $now = now();
            if ($now->greaterThanOrEqualTo($session->idle_expires_at) || $now->greaterThanOrEqualTo($session->absolute_expires_at)) {
                $session->forceFill([
                    'revoked_at' => $now,
                    'revocation_reason' => 'expired',
                ])->save();

                $this->audit($organization, $actor, 'engineer.session_expired', $session, ['reason' => 'idle_or_absolute_timeout']);

                return ['session' => null, 'error' => 'Engineer session expired.'];
            }

            if ($actor->status !== 'active' || $organization->status !== 'active') {
                $session->forceFill([
                    'revoked_at' => $now,
                    'revocation_reason' => 'account_or_tenant_inactive',
                ])->save();

                $this->audit($organization, $actor, 'engineer.session_revoked', $session, ['reason' => 'account_or_tenant_inactive']);

                return ['session' => null, 'error' => 'Engineer access is no longer active.'];
            }

            $membershipStillActive = $actor->organizations()
                ->wherePivot('status', 'active')
                ->where('organizations.status', 'active')
                ->whereKey($organization->getKey())
                ->exists();

            if (! $membershipStillActive) {
                $session->forceFill([
                    'revoked_at' => $now,
                    'revocation_reason' => 'membership_revoked',
                ])->save();

                $this->audit($organization, $actor, 'engineer.session_revoked', $session, ['reason' => 'membership_revoked']);

                return ['session' => null, 'error' => 'Engineer organization membership is no longer active.'];
            }

            if (! $this->hasActiveGrant($actor, $organization, $now)) {
                $session->forceFill([
                    'revoked_at' => $now,
                    'revocation_reason' => 'engineer_grant_missing_or_expired',
                ])->save();

                $this->audit($organization, $actor, 'engineer.session_revoked', $session, ['reason' => 'engineer_grant_missing_or_expired']);

                return ['session' => null, 'error' => 'Engineer access grant is missing, expired, or revoked.'];
            }

            $idleExpiry = $now->copy()->addSeconds($idleTimeoutSeconds);
            if ($idleExpiry->greaterThan($session->absolute_expires_at)) {
                $idleExpiry = $session->absolute_expires_at;
            }

            $session->forceFill([
                'last_activity_at' => $now,
                'idle_expires_at' => $idleExpiry,
            ])->save();

            $this->audit($organization, $actor, 'engineer.session_activity', $session);

            return ['session' => $session, 'error' => null];
        });

        // Throw only after the transaction commits so expiry/revocation and its
        // audit record are not rolled back by exception handling.
        if ($outcome['error'] !== null) {
            throw new RuntimeException($outcome['error']);
        }

        return $outcome['session'];
    }

    public function deactivate(User $actor, Organization $organization, string $token, string $command): void
    {
        if (! hash_equals(self::DEACTIVATE_COMMAND, $command)) {
            throw new RuntimeException('Engineer session deactivation command not recognised.');
        }

        DB::transaction(function () use ($actor, $organization, $token) {
            $session = EngineerSession::query()
                ->where('organization_id', $organization->getKey())
                ->where('actor_user_id', $actor->getKey())
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new RuntimeException('No matching active engineer session.');
            }

            $session->forceFill([
                'revoked_at' => now(),
                'revocation_reason' => 'operator_command',
            ])->save();

            $this->audit($organization, $actor, 'engineer.session_deactivated', $session, ['reason' => 'operator_command']);
        });
    }

    private function hasActiveGrant(User $actor, Organization $organization, Carbon $at): bool
    {
        return EngineerAccessGrant::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereNotNull('scope')
            ->where('starts_at', '<=', $at)
            ->where(function ($query) use ($at) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $at);
            })
            ->whereColumn('granted_by_user_id', '!=', 'approved_by_user_id')
            ->exists();
    }

    private function audit(Organization $organization, User $actor, string $action, EngineerSession $session, array $metadata = []): void
    {
        AuditLog::create([
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => EngineerSession::class,
            'subject_id' => (string) $session->public_id,
            'metadata' => $metadata,
        ]);
    }
}
