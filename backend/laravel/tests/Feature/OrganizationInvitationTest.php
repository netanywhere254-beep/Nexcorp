<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_owner_can_invite_a_new_member_and_the_token_is_hashed_at_rest(): void
    {
        $organization = $this->makeOrganization('invite-owner');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');

        $response = $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'Invitee@Example.Test',
                'role' => 'member',
            ])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'invitee@example.test')
            ->assertJsonPath('invitation.role', 'member')
            ->assertJsonPath('invitation.status', 'pending')
            ->assertJsonPath('invitation.email_sent', true)
            ->assertJsonMissingPath('invitation.acceptance_token');

        $token = $this->sentInvitationToken();

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'invited_by_user_id' => $owner->id,
            'email' => 'invitee@example.test',
            'role' => 'member',
            'status' => 'pending',
            'token_hash' => hash('sha256', $token),
        ]);

        $stored = OrganizationInvitation::firstOrFail();
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $owner->id,
            'action' => 'organization.invitation_sent',
        ]);

        $metadata = AuditLog::where('action', 'organization.invitation_sent')->firstOrFail()->metadata;
        $this->assertArrayNotHasKey('token', $metadata);
        $this->assertArrayNotHasKey('email', $metadata);

        Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
    }

    public function test_only_an_owner_may_invite_another_administrator(): void
    {
        $organization = $this->makeOrganization('invite-admin');
        $owner = $this->makeUser('owner@example.test');
        $admin = $this->makeUser('admin@example.test');
        $this->attach($owner, $organization, 'owner');
        $this->attach($admin, $organization, 'admin');

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'another-admin@example.test',
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'staff@example.test',
                'role' => 'member',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'trusted-admin@example.test',
                'role' => 'admin',
            ])
            ->assertCreated();
    }

    public function test_manager_cannot_invite_members(): void
    {
        $organization = $this->makeOrganization('invite-manager');
        $manager = $this->makeUser('manager@example.test');
        $this->attach($manager, $organization, 'manager');

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'invitee@example.test',
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_invitations', 0);
    }

    public function test_owner_role_and_unknown_roles_cannot_be_invited(): void
    {
        $organization = $this->makeOrganization('invite-invalid-role');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');

        foreach (['owner', 'superadmin', 'root'] as $role) {
            $this->actingAs($owner)
                ->withSession(['active_organization_id' => $organization->id])
                ->postJson('/organization/invitations', [
                    'email' => $role.'@example.test',
                    'role' => $role,
                ])
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('organization_invitations', 0);
    }

    public function test_an_existing_member_cannot_be_invited_again(): void
    {
        $organization = $this->makeOrganization('invite-existing');
        $owner = $this->makeUser('owner@example.test');
        $member = $this->makeUser('member@example.test');
        $this->attach($owner, $organization, 'owner');
        $this->attach($member, $organization, 'member');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'member@example.test',
                'role' => 'member',
            ])
            ->assertStatus(409);
    }

    public function test_a_duplicate_pending_invitation_is_rejected(): void
    {
        $organization = $this->makeOrganization('invite-duplicate');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');

        $this->invite($organization, $owner, 'invitee@example.test');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'invitee@example.test',
                'role' => 'member',
            ])
            ->assertStatus(409);
    }

    public function test_invitee_can_accept_and_becomes_an_active_member(): void
    {
        $organization = $this->makeOrganization('invite-accept');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test', 'billing');

        $invitee = $this->makeUser('invitee@example.test');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('organization.role', 'billing')
            ->assertJsonPath('invitation.status', 'accepted');

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
            'role' => 'billing',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'invitee@example.test',
            'status' => 'accepted',
            'accepted_by_user_id' => $invitee->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $invitee->id,
            'action' => 'organization.invitation_accepted',
        ]);
    }

    public function test_invitation_cannot_be_accepted_by_a_different_email(): void
    {
        $organization = $this->makeOrganization('invite-wrong-email');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test');

        $other = $this->makeUser('other@example.test');

        $this->actingAs($other)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertForbidden();

        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_unverified_account_cannot_accept_an_invitation(): void
    {
        $organization = $this->makeOrganization('invite-unverified');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test');

        $invitee = $this->makeUser('invitee@example.test', ['email_verified_at' => null]);

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_members', 1);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $organization = $this->makeOrganization('invite-expired');
        $invitee = $this->makeUser('invitee@example.test');
        $token = bin2hex(random_bytes(32));

        OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'invited_by_user_id' => null,
            'email' => 'invitee@example.test',
            'role' => 'member',
            'token_hash' => hash('sha256', $token),
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertStatus(410);
    }

    public function test_revoked_invitation_cannot_be_accepted(): void
    {
        $organization = $this->makeOrganization('invite-revoked');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [$response, $token] = $this->invite($organization, $owner, 'invitee@example.test');
        $invitationId = $response->json('invitation.id');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $organization->id])
            ->deleteJson('/organization/invitations/'.$invitationId)
            ->assertOk();

        $invitee = $this->makeUser('invitee@example.test');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertStatus(409);

        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invitationId,
            'status' => 'revoked',
        ]);
    }

    public function test_invitee_can_decline_and_then_cannot_accept(): void
    {
        $organization = $this->makeOrganization('invite-decline');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test');

        $invitee = $this->makeUser('invitee@example.test');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/decline', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('invitation.status', 'declined');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertStatus(409);

        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_an_invitation_token_is_single_use(): void
    {
        $organization = $this->makeOrganization('invite-single-use');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test');

        $invitee = $this->makeUser('invitee@example.test');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertOk();

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertStatus(409);
    }

    public function test_organization_administrators_only_see_and_revoke_their_own_organization_invitations(): void
    {
        $first = $this->makeOrganization('tenant-first');
        $second = $this->makeOrganization('tenant-second');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $first, 'owner');

        $this->invite($first, $owner, 'first-invitee@example.test');

        $secondInvitation = OrganizationInvitation::create([
            'organization_id' => $second->id,
            'invited_by_user_id' => null,
            'email' => 'second-invitee@example.test',
            'role' => 'member',
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $first->id])
            ->getJson('/organization/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'first-invitee@example.test');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $first->id])
            ->deleteJson('/organization/invitations/'.$secondInvitation->id)
            ->assertNotFound();

        $this->assertDatabaseHas('organization_invitations', [
            'id' => $secondInvitation->id,
            'status' => 'pending',
        ]);
    }

    public function test_pending_invitation_list_is_scoped_to_the_signed_in_email(): void
    {
        $organization = $this->makeOrganization('invite-pending-list');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        $this->invite($organization, $owner, 'first@example.test');
        $this->invite($organization, $owner, 'second@example.test');

        $first = $this->makeUser('first@example.test');

        $this->actingAs($first)
            ->getJson('/auth/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'first@example.test');
    }

    public function test_preview_is_public_but_reports_when_the_invitation_is_no_longer_usable(): void
    {
        $organization = $this->makeOrganization('invite-preview');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test');

        $this->getJson('/auth/invitations/preview/'.$token)
            ->assertOk()
            ->assertJsonPath('usable', true)
            ->assertJsonPath('invitation.organization.slug', 'invite-preview')
            ->assertJsonPath('invitation.email', 'invitee@example.test');

        $invitee = $this->makeUser('invitee@example.test');
        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertOk();

        $this->getJson('/auth/invitations/preview/'.$token)
            ->assertOk()
            ->assertJsonPath('usable', false)
            ->assertJsonPath('invitation.status', 'accepted');
    }

    public function test_unknown_invitation_token_returns_not_found(): void
    {
        $this->getJson('/auth/invitations/preview/'.str_repeat('a', 64))
            ->assertNotFound();

        $user = $this->makeUser('user@example.test');

        $this->actingAs($user)
            ->postJson('/auth/invitations/accept', ['token' => str_repeat('b', 64)])
            ->assertNotFound();
    }

    public function test_unauthenticated_and_unverified_users_cannot_manage_invitations(): void
    {
        $organization = $this->makeOrganization('invite-guards');
        $unverified = $this->makeUser('unverified@example.test', ['email_verified_at' => null]);
        $this->attach($unverified, $organization, 'owner');

        $this->postJson('/organization/invitations', [
            'email' => 'invitee@example.test',
            'role' => 'member',
        ])->assertUnauthorized();

        $this->actingAs($unverified)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => 'invitee@example.test',
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_invitations', 0);
    }

    public function test_invitation_email_matching_is_case_insensitive(): void
    {
        $organization = $this->makeOrganization('invite-case');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'Mixed.Case@Example.Test');

        $invitee = $this->makeUser('mixed.case@example.test');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertOk();

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
            'status' => 'active',
        ]);
    }

    public function test_invitation_cannot_be_accepted_into_a_suspended_organization(): void
    {
        $organization = $this->makeOrganization('invite-suspended');
        $owner = $this->makeUser('owner@example.test');
        $this->attach($owner, $organization, 'owner');
        [, $token] = $this->invite($organization, $owner, 'invitee@example.test');

        $organization->forceFill(['status' => 'suspended'])->save();

        $invitee = $this->makeUser('invitee@example.test');

        $this->actingAs($invitee)
            ->postJson('/auth/invitations/accept', ['token' => $token])
            ->assertStatus(409);

        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
        ]);
    }

    private function invite(
        Organization $organization,
        User $actor,
        string $email,
        string $role = 'member'
    ): array {
        $response = $this->actingAs($actor)
            ->withSession(['active_organization_id' => $organization->id])
            ->postJson('/organization/invitations', [
                'email' => $email,
                'role' => $role,
            ])
            ->assertCreated();

        return [$response, $this->sentInvitationToken()];
    }

    private function sentInvitationToken(): string
    {
        $token = null;

        Notification::assertSentOnDemand(
            OrganizationInvitationNotification::class,
            function (OrganizationInvitationNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            }
        );

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        return $token;
    }

    private function makeUser(string $email, array $overrides = []): User
    {
        $verifiedAt = array_key_exists('email_verified_at', $overrides)
            ? $overrides['email_verified_at']
            : now();
        unset($overrides['email_verified_at']);

        $user = User::create(array_merge([
            'name' => 'Invitation Test User',
            'email' => $email,
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ], $overrides));

        // email_verified_at is deliberately not mass assignable.
        $user->forceFill(['email_verified_at' => $verifiedAt])->save();

        return $user;
    }

    private function makeOrganization(string $slug): Organization
    {
        return Organization::create([
            'name' => 'Organization '.$slug,
            'slug' => $slug,
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    private function attach(User $user, Organization $organization, string $role): void
    {
        $user->organizations()->attach($organization->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
