<?php

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use App\Services\ClientChangeOutboxPublisher;
use App\Services\ClientChangeInboxProcessor;
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

Artisan::command('nexora:inbox:process {--limit=100}', function (ClientChangeInboxProcessor $processor) {
    $result = $processor->processPending((int) $this->option('limit'));

    $this->info("Processed: {$result['processed']}; failed: {$result['failed']}; waiting for earlier events: {$result['blocked']}.");

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Apply supported, ordered client identity events received from the primary.');

Artisan::command('nexora:continuity:status', function () {
    $outboxPending = DB::table('client_change_outbox')->whereNull('published_at')->count();
    $outboxFailed = DB::table('client_change_outbox')->whereNull('published_at')->where('attempts', '>', 0)->count();
    $inboxPending = DB::table('client_change_inbox')->whereNull('processed_at')->count();
    $inboxFailed = DB::table('client_change_inbox')->whereNull('processed_at')->where('attempts', '>', 0)->count();
    $oldestOutbox = DB::table('client_change_outbox')->whereNull('published_at')->min('created_at');
    $oldestInbox = DB::table('client_change_inbox')->whereNull('processed_at')->min('received_at');

    $this->table(
        ['Continuity queue', 'Pending', 'With failed attempts', 'Oldest pending'],
        [
            ['Primary outbox', $outboxPending, $outboxFailed, $oldestOutbox ?: '—'],
            ['Backup inbox', $inboxPending, $inboxFailed, $oldestInbox ?: '—'],
        ]
    );

    $unprocessedGroups = DB::table('client_change_inbox')
        ->select('product_code', 'aggregate_type', 'event_type', DB::raw('COUNT(*) as event_count'))
        ->whereNull('processed_at')
        ->groupBy('product_code', 'aggregate_type', 'event_type')
        ->orderBy('product_code')
        ->orderBy('event_type')
        ->get();

    if ($unprocessedGroups->isNotEmpty()) {
        $this->warn('Unprocessed backup event types (review handler support and prerequisites):');
        $this->table(
            ['Product', 'Aggregate', 'Event type', 'Count'],
            $unprocessedGroups->map(fn ($event) => [
                $event->product_code,
                $event->aggregate_type,
                $event->event_type,
                $event->event_count,
            ])->all()
        );
    }

    $this->comment('Read-only diagnostic. It does not publish, process, retry, or delete events.');
    return 0;
})->purpose('Show read-only primary outbox and backup inbox backlog and failure counts.');


Schedule::command('nexora:outbox:publish --limit=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

