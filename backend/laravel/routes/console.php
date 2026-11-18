<?php

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    try {
        DB::transaction(function () use ($email) {
            // Lock an existing user row to serialize simultaneous first-CEO attempts.
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
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

            $authority = GroupAuthority::create([
                'user_id' => $user->id,
                'authority' => 'GROUP_CEO',
                'scope_type' => 'group',
                'scope_key' => 'nexora',
                'status' => 'active',
                'appointed_by' => null,
                'starts_at' => now(),
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
                ],
            ]);
        });
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        return 1;
    }

    $this->info('Initial Nexora Group CEO authority created. Remove NEXORA_GROUP_CEO_BOOTSTRAP_KEY from the runtime environment now.');
    return 0;
})->purpose('Perform the one-time, secret-protected initial Nexora Group CEO bootstrap.');
