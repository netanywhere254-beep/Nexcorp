<?php

namespace App\Services;

use App\Models\ApprovalOtpChallenge;
use App\Models\AuditLog;
use App\Models\AuthorizedApprovalPhone;
use App\Models\GovernanceApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApprovalOtpService
{
    public function send(GovernanceApproval $approval, User $actor): string
    {
        $this->assertAssignedApprover($approval, $actor);

        $endpoint = config('services.approval_sms.url');
        $token = config('services.approval_sms.token');
        if (! is_string($endpoint) || $endpoint === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Approval SMS delivery is not configured.');
        }

        $phone = AuthorizedApprovalPhone::query()
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->latest('verified_at')
            ->first();

        if (! $phone) {
            throw new RuntimeException('No pre-approved, verified approval phone is registered.');
        }

        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($approval, $actor, $phone, $code) {
            ApprovalOtpChallenge::query()
                ->where('governance_approval_id', $approval->id)
                ->where('approver_user_id', $actor->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return ApprovalOtpChallenge::create([
                'governance_approval_id' => $approval->id,
                'authorized_approval_phone_id' => $phone->id,
                'approver_user_id' => $actor->id,
                'otp_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($this->otpTtlMinutes()),
            ]);
        });

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->post($endpoint, [
                    'to' => $phone->phone_e164,
                    'message' => 'Nexora approval code: '.$code.'. It expires in '
                        .(int) config('services.approval_sms.ttl_minutes', 5)
                        .' minutes. Never share this code.',
                    'reference' => 'NEXORA-APPROVAL-'.$challenge->id,
                    'sender_id' => config('services.approval_sms.sender_id'),
                ]);
        } catch (\Throwable $exception) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            throw new RuntimeException('The approval OTP could not be delivered.', previous: $exception);
        }

        if (! $response->successful()) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            throw new RuntimeException('The approval OTP could not be delivered.');
        }

        return $this->maskPhone($phone->phone_e164);
    }

    public function confirm(
        GovernanceApproval $approval,
        User $actor,
        string $code,
        string $decision,
        ?string $comment = null
    ): void {
        $error = DB::transaction(function () use ($approval, $actor, $code, $decision, $comment) {
            $lockedApproval = GovernanceApproval::query()->lockForUpdate()->findOrFail($approval->id);
            $this->assertAssignedApprover($lockedApproval, $actor);

            $challenge = ApprovalOtpChallenge::query()
                ->where('governance_approval_id', $lockedApproval->id)
                ->where('approver_user_id', $actor->id)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->expires_at->isPast()) {
                return 'The approval OTP is missing or has expired.';
            }

            if ($challenge->attempts >= 5) {
                $challenge->forceFill(['consumed_at' => now()])->save();
                return 'The approval OTP attempt limit has been reached.';
            }

            if (! Hash::check($code, $challenge->otp_hash)) {
                $challenge->increment('attempts');
                if ($challenge->fresh()->attempts >= 5) {
                    $challenge->forceFill(['consumed_at' => now()])->save();
                }
                return 'The approval OTP is incorrect.';
            }

            $phone = $challenge->phone;
            if (! $phone || $phone->status !== 'active' || $phone->revoked_at || ! $phone->verified_at) {
                return 'The registered approval phone is no longer authorized.';
            }

            $challenge->forceFill(['consumed_at' => now()])->save();
            $lockedApproval->forceFill([
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ])->save();

            $request = $lockedApproval->governanceRequest()->lockForUpdate()->firstOrFail();
            if ($decision === 'rejected') {
                $request->forceFill(['status' => 'rejected', 'completed_at' => now()])->save();
            } elseif (! $request->approvals()->where('decision', 'pending')->exists()) {
                $request->forceFill(['status' => 'approved', 'completed_at' => now()])->save();
            }

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $actor->id,
                'action' => 'governance.approval_'.$decision,
                'subject_type' => GovernanceApproval::class,
                'subject_id' => (string) $lockedApproval->id,
                'metadata' => ['approval_level' => $lockedApproval->approval_level],
            ]);

            return null;
        });

        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    private function otpTtlMinutes(): int
    {
        return max(1, min(15, (int) config('services.approval_sms.ttl_minutes', 5)));
    }

    private function assertAssignedApprover(GovernanceApproval $approval, User $actor): void
    {
        abort_unless((int) $approval->approver_user_id === (int) $actor->id, 403);
        abort_unless($approval->decision === 'pending', 409, 'This approval has already been decided.');

        $request = $approval->governanceRequest;
        abort_unless($request && $request->status === 'pending', 409, 'This governance request is not pending.');
        abort_unless(! $request->expires_at || $request->expires_at->isFuture(), 410, 'This governance request has expired.');
        abort_unless((int) $request->requested_by !== (int) $actor->id, 403, 'A requester cannot approve their own request.');
    }

    private function maskPhone(string $phone): string
    {
        return '••••••'.substr($phone, -4);
    }
}
