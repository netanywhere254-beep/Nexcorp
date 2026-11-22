<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NexispCustomer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispCustomerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/nexisp/customers')->assertUnauthorized();
    }

    public function test_endpoints_require_a_selected_organization(): void
    {
        $this->actingAs($this->makeUser())->getJson('/nexisp/customers')->assertStatus(409);
    }

    public function test_billing_can_create_and_tenant_comes_from_the_session_not_the_body(): void
    {
        [$user, $org] = $this->memberWithRole('billing');
        $other = $this->makeOrganization();

        $this->asMember($user, $org)
            ->postJson('/nexisp/customers', [
                'name' => 'Jane Wanjiru',
                'phone' => '+254700111222',
                'organization_id' => $other->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jane Wanjiru')
            ->assertJsonPath('data.has_login', false);

        $this->assertDatabaseHas('nexisp_customers', [
            'organization_id' => $org->id,
            'name' => 'Jane Wanjiru',
        ]);
        $this->assertDatabaseMissing('nexisp_customers', ['organization_id' => $other->id]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'nexisp.customer.created',
        ]);
    }

    public function test_plain_member_cannot_list_or_create(): void
    {
        [$user, $org] = $this->memberWithRole('member');

        $this->asMember($user, $org)->getJson('/nexisp/customers')->assertForbidden();
        $this->asMember($user, $org)
            ->postJson('/nexisp/customers', ['name' => 'X Y', 'phone' => '+254700111222'])
            ->assertForbidden();
    }

    public function test_support_can_read_but_not_create_or_update_and_phone_is_masked(): void
    {
        [$user, $org] = $this->memberWithRole('support');
        $customer = $this->makeCustomer($org, '+254700111222');

        $this->asMember($user, $org)->getJson('/nexisp/customers')
            ->assertOk()
            ->assertJsonMissing(['phone' => '+254700111222']);
        $this->asMember($user, $org)->getJson("/nexisp/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonMissing(['phone' => '+254700111222']);
        $this->asMember($user, $org)
            ->postJson('/nexisp/customers', ['name' => 'New One', 'phone' => '+254711222333'])
            ->assertForbidden();
        $this->asMember($user, $org)
            ->patchJson("/nexisp/customers/{$customer->id}", ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_a_customer_from_another_organization_is_not_found(): void
    {
        [$user, $org] = $this->memberWithRole('owner');
        $other = $this->makeOrganization();
        $foreign = $this->makeCustomer($other, '+254700999888', 'Foreign Customer');

        $this->asMember($user, $org)->getJson("/nexisp/customers/{$foreign->id}")->assertNotFound();
        $this->asMember($user, $org)
            ->patchJson("/nexisp/customers/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Foreign Customer', $foreign->fresh()->name);
        $this->asMember($user, $org)->getJson('/nexisp/customers')->assertJsonPath('meta.total', 0);
    }

    public function test_duplicate_phone_in_the_same_organization_is_rejected_even_if_formatted_differently(): void
    {
        [$user, $org] = $this->memberWithRole('admin');
        $this->makeCustomer($org, '+254700111222');

        $this->asMember($user, $org)
            ->postJson('/nexisp/customers', [
                'name' => 'Dup Person',
                'phone' => '+254 700 111 222',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_list_masks_phone_while_billing_show_can_reveal_it(): void
    {
        [$user, $org] = $this->memberWithRole('billing');
        $customer = $this->makeCustomer($org, '+254700111222');

        $list = $this->asMember($user, $org)->getJson('/nexisp/customers')->assertOk();
        $this->assertStringNotContainsString('700111', $list->json('data.0.phone'));

        $this->asMember($user, $org)
            ->getJson("/nexisp/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.phone', '+254700111222');
    }

    public function test_update_changes_name_and_status_but_ignores_phone_and_audits_without_pii(): void
    {
        [$user, $org] = $this->memberWithRole('billing');
        $customer = $this->makeCustomer($org, '+254700111222');

        $this->asMember($user, $org)
            ->patchJson("/nexisp/customers/{$customer->id}", [
                'name' => 'Renamed Customer',
                'status' => 'suspended',
                'phone' => '+254799000111',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $fresh = $customer->fresh();
        $this->assertSame('Renamed Customer', $fresh->name);
        $this->assertSame('+254700111222', $fresh->phone);

        $log = AuditLog::where('action', 'nexisp.customer.updated')->firstOrFail();
        $this->assertSame($org->id, $log->organization_id);
        $this->assertStringNotContainsString('254700', json_encode($log->metadata));
        $this->assertStringNotContainsString('Renamed', json_encode($log->metadata));
    }

    private function asMember(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['active_organization_id' => $org->id]);
    }

    private function memberWithRole(string $role): array
    {
        $org = $this->makeOrganization();
        $user = $this->makeUser();
        $user->organizations()->attach($org->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $org];
    }

    private function makeCustomer(Organization $org, string $phone, string $name = 'Test Customer'): NexispCustomer
    {
        return NexispCustomer::create([
            'organization_id' => $org->id,
            'customer_number' => 'NXC-'.strtoupper(uniqid()),
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    private function makeUser(): User
    {
        $user = User::create([
            'name' => 'Customer Endpoint User',
            'email' => 'cust-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Customer Endpoint Org '.uniqid(),
            'slug' => 'cust-endpoint-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }
}
