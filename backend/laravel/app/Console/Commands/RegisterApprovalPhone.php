<?php

namespace App\Console\Commands;

use App\Models\ApprovalOtpChallenge;
use App\Models\AuditLog;
use App\Models\AuthorizedApprovalPhone;
use App\Models\GroupAuthority;
use App\Models\User;
use Illuminate\Console\Command;

class RegisterApprovalPhone extends Command
{
    protected $signature = 'nexora:approval-phone:add {user_id} {phone} {--approved-by=}';

    protected $description = 'Provision a pre-verified phone for a governance approver (trusted server operators only).';

    public function handle(): int
    {
        $user = User::find($this->argument('user_id'));
        $approver = User::find($this->option('approved-by'));

        if (! $user || ! $approver) {
            $this->error('The approver and target user must both exist.');
            return self::FAILURE;
        }

        $authorized = GroupAuthority::query()
            ->where('user_id', $approver->id)
            ->whereIn('authority', ['CEO', 'SECURITY_ADMIN'])
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $authorized) {
            $this->error('Only an active Nexora CEO or Security Administrator may provision approval numbers.');
            return self::FAILURE;
        }

        $phone = $this->argument('phone');
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            $this->error('Use an E.164 number, for example +2547XXXXXXXX.');
            return self::FAILURE;
        }

        if (! $this->confirm('Confirm this number has been independently verified and approved for this person?')) {
            $this->warn('No number was registered.');
            return self::SUCCESS;
        }

        $existingPhone = AuthorizedApprovalPhone::query()
            ->where('phone_hash', hash_hmac('sha256', $phone, (string) config('app.key')))
            ->first();

        if ($existingPhone && (int) $existingPhone->user_id !== (int) $user->id) {
            $this->error('This number is already assigned to another account. Revoke it through the approved security process before reassignment.');
            return self::FAILURE;
        }

        $phoneRecord = AuthorizedApprovalPhone::updateOrCreate(
            ['phone_hash' => hash_hmac('sha256', $phone, (string) config('app.key'))],
            [
                'user_id' => $user->id,
                'phone_e164' => $phone,
                'status' => 'active',
                'verified_at' => now(),
                'approved_by' => $approver->id,
                'revoked_at' => null,
            ],
        );

        ApprovalOtpChallenge::query()
            ->where('authorized_approval_phone_id', $phoneRecord->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $approver->id,
            'action' => 'governance.approval_phone_registered',
            'subject_type' => AuthorizedApprovalPhone::class,
            'subject_id' => (string) $phoneRecord->id,
            'metadata' => ['assigned_user_id' => $user->id],
        ]);

        $this->info('Pre-approved phone registered. It is available only to the assigned approver.');
        return self::SUCCESS;
    }
}
