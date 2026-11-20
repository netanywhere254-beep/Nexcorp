<?php

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use App\Services\ClientChangeOutboxPublisher;
use Illuminate\Validation\ValidationException;

Artisan::command('nexora:about', function () {
    $this->comment('Nexora primary Laravel application foundation.');
})->purpose('Display basic Nexora application information.');

Artisan::command('nexora:bootstrap-group-ceo {email}', function (string $email) {
    $expectedKey = (string) config('services.nexora_group_ceo_bootstrap_key');
    if ($expectedKey === '') {
        $this->error('NEXORA_GROUP_CEO_BOOTSTRAP_KEY is not configured.');
        return 1;
    }

    $providedKey = (string) $this->secret('One-time bootstrap key');
    if (! hash_equals($expectedKey, $providedKey)) {
        $this->error('Invalid bootstrap key.');
        return 1;
    }

    $phone = (string) $this->secret('Initial CEO phone in E.164 format');
    if (! preg_match('/^\\+[1-9][0-9]{7,14}$/', $phone)) {
        $this->error('Provide the CEO phone in E.164 format, for example +2547XXXXXXXX.');
        return 1;
    }

    try {
        DB::transaction(function () use ($email, $phone) {
            // A stable global row lock serializes simultaneous first-CEO attempts, even for different emails.
            User::query()->orderBy('id')->lockForUpdate()->first();
            $user = User::query()->where('email', $email)->first();
            if (! $user || $user->status !== 'active' || ! $user->email_verified_at) {
                throw ValidationException::withMessages([
                    'email' => 'The CEO account must already exist, be active, and have a verified email.',
                ]);
            }

            $everBootstrapped = GroupAuthority::query()
                ->where('authority', 'GROUP_CEO')
                ->where('scope_type', 'group')
                ->where('scope_key', 'nexora')
                ->exists();

            if ($everBootstrapped) {
                throw ValidationException::withMessages([
                    'authority' => 'The one-time CEO bootstrap has already been used. Use the formal succession process.',
                ]);
            }

            if (GroupStaffApprovedPhone::where('phone_e164', $phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone' => 'This phone number is already assigned to a group staff account.',
                ]);
            }

            $authority = GroupAuthority::create([
                'user_id' => $user->id,
                'authority' => 'GROUP_CEO',
                'scope_type' => 'group',
                'scope_key' => 'nexora',
                'status' => 'active',
                'appointed_by' => null,
                'starts_at' => now(),
            ]);

            // This is a one-time, operator-controlled initial enrollment, not a self-service approval.
            // The CEO must still prove possession through the normal SMS OTP before sensitive actions.
            $approvedPhone = GroupStaffApprovedPhone::create([
                'user_id' => $user->id,
                'phone_e164' => $phone,
                'approved_by' => null,
                'approval_method' => 'one_time_bootstrap',
                'approved_at' => now(),
                'status' => 'active',
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => null,
                'action' => 'group.ceo.initial_bootstrap',
                'subject_type' => GroupAuthority::class,
                'subject_id' => (string) $authority->id,
                'metadata' => [
                    'user_id' => $user->id,
                    'method' => 'one_time_cli_bootstrap',
                    'approved_phone_id' => $approvedPhone->id,
                    'phone_last_four' => substr($phone, -4),
                ],
            ]);
        });
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }

    $this->info('Initial Nexora Group CEO authority and bootstrap phone enrollment created.');
    $this->warn('Verify phone possession with the normal group staff OTP before any sensitive governance action.');
    $this->info('Remove NEXORA_GROUP_CEO_BOOTSTRAP_KEY from the runtime environment now.');
    return 0;
})->purpose('Perform the one-time, secret-protected initial Nexora Group CEO and phone bootstrap.');

Artisan::command('nexora:outbox:publish {--limit=100}', function (ClientChangeOutboxPublisher $publisher) {
    $limit = (int) $this->option('limit');
    $result = $publisher->publishPending($limit);

    if (! $result['configured']) {
        $this->warn('Replication is not configured. Set NEXORA_REPLICATION_URL and NEXORA_REPLICATION_SECRET.');
        return 0;
    }

    $this->info("Published: {$result['published']}; failed: {$result['failed']}.");
    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Publish durable client change events to the configured Nexora backup receiver.');

Schedule::command('nexora:outbox:publish --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

