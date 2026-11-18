<?php

namespace App\Console\Commands;

use App\Models\ApprovalOtpChallenge;
use App\Models\AuditLog;
use App\Models\AuthorizedApprovalPhone;
use App\Models\GroupAuthority;
use App\Models\User;
use Illuminate\Console\Command;

class RevokeApprovalPhone extends Command
{
    protected $signature = 'nexora:approval-phone:revoke {phone} {--approved-by=}';

    protected $description = 'Revoke a pre-approved governance phone number.';

    public function handle(): int
    {
        $approver = User::find($this->option('approved-by'));
        if (! $approver || ! GroupAuthority::query()
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
            ->exists()) {
            $this->error('Only an active Nexora CEO or Security Administrator may revoke approval numbers.');
            return self::FAILURE;
        }

        $phone = $this->argument('phone');
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            $this->error('Use an E.164 phone number.');
            return self::FAILURE;
        }

        $record = AuthorizedApprovalPhone::query()
            ->where('phone_hash', hash_hmac('sha256', $phone, (string) config('app.key')))
            ->where('status', 'active')
            ->first();

        if (! $record) {
            $this->error('No active pre-approved record matches that number.');
            return self::FAILURE;
        }

        if (! $this->confirm('Revoke the selected pre-approved phone number?')) {
            $this->warn('No number was revoked.');
            return self::SUCCESS;
        }

        $record->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        ApprovalOtpChallenge::query()
            ->where('authorized_approval_phone_id', $record->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $approver->id,
            'action' => 'governance.approval_phone_revoked',
            'subject_type' => AuthorizedApprovalPhone::class,
            'subject_id' => (string) $record->id,
            'metadata' => ['assigned_user_id' => $record->user_id],
        ]);

        $this->info('Approval number revoked.');
        return self::SUCCESS;
    }
}
