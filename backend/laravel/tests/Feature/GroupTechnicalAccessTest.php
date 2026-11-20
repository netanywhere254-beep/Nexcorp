<?php

namespace Tests\\Feature;

use App\\Models\\GroupAuthority;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Gate;
use Tests\\TestCase;

class GroupTechnicalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $status = 'active'): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => $status,
            'email_verified_at' => now(),
        ]);
    }

    private function appoint(User $user, array $overrides = []): GroupAuthority
    {
        return GroupAuthority::create(array_merge([
            'user_id' => $user->id,
            'authority' => 'GROUP_IT_MANAGER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_active_group_it_manager_receives_only_allowlisted_technical_capabilities(): void
    {
        $manager = $this->user('IT Manager');
        $this->appoint($manager);

        foreach ([
            'users.directory.view',
            'system_health.view',
            'integrations.manage',
            'security_events.view',
            'backups.jobs.manage',
            'deployments.manage',
            'audit_logs.view',
        ] as $capability) {
            $this->assertTrue(
                Gate::forUser($manager)->allows('group.technical', $capability),
                "Expected IT Manager capability [{$capability}] to be allowed.",
            );
        }

        foreach ([
            'finance.manage',
            'governance.decide',
            'group_authorities.manage',
            'staff_approval_phones.manage',
            'trace_find.case_contents.view',
            'unknown.capability',
        ] as $capability) {
            $this->assertFalse(
                Gate::forUser($manager)->allows('group.technical', $capability),
                "Expected IT Manager capability [{$capability}] to be denied.",
            );
        }
    }

    public function test_technical_capabilities_require_an_active_group_scoped_it_manager_appointment(): void
    {
        $ordinaryStaff = $this->user('Ordinary Staff');
        GroupAuthority::create([
            'user_id' => $ordinaryStaff->id,
            'authority' => 'GROUP_AUDITOR',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $this->assertFalse(Gate::forUser($ordinaryStaff)->allows('group.technical', 'system_health.view'));

        $tenantOnly = $this->user('Tenant IT Manager');
        $this->appoint($tenantOnly, ['scope_type' => 'organization', 'scope_key' => 'tenant-1']);
        $this->assertFalse(Gate::forUser($tenantOnly)->allows('group.technical', 'system_health.view'));

        $revoked = $this->user('Revoked IT Manager');
        $this->appoint($revoked, ['status' => 'revoked']);
        $this->assertFalse(Gate::forUser($revoked)->allows('group.technical', 'system_health.view'));

        $expired = $this->user('Expired IT Manager');
        $this->appoint($expired, ['expires_at' => now()->subSecond()]);
        $this->assertFalse(Gate::forUser($expired)->allows('group.technical', 'system_health.view'));

        $notYetActive = $this->user('Future IT Manager');
        $this->appoint($notYetActive, ['starts_at' => now()->addMinute()]);
        $this->assertFalse(Gate::forUser($notYetActive)->allows('group.technical', 'system_health.view'));
    }

    public function test_suspended_user_cannot_use_an_active_it_manager_appointment(): void
    {
        $manager = $this->user('Suspended IT Manager', 'suspended');
        $this->appoint($manager);

        $this->assertFalse(Gate::forUser($manager)->allows('group.technical', 'system_health.view'));
    }
}
