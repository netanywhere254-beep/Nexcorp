<?php
namespace Tests\Feature;
use App\Models\Organization;
use App\Models\OrganizationStaffApprovedPhone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
class OrganizationStaffOtpTest extends TestCase {
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp(); Notification::fake(); }
 private function setupOrg(string $role='owner'): array {
  $admin=User::create(['name'=>'Org Admin','email'=>'admin-'.uniqid().'@example.test','password'=>'Correct-Horse-42!','status'=>'active','email_verified_at'=>now()]);
  $staff=User::create(['name'=>'Staff','email'=>'staff-'.uniqid().'@example.test','password'=>'Correct-Horse-42!','status'=>'active','email_verified_at'=>now()]);
  $admin->markEmailAsVerified(); $staff->markEmailAsVerified();
  $org=Organization::create(['name'=>'OTP Test Org','slug'=>'otp-'.uniqid(),'type'=>'business','status'=>'active','country_code'=>'KE','timezone'=>'Africa/Nairobi']);
  $org->users()->attach($admin->id,['role'=>$role,'status'=>'active','joined_at'=>now()]);
  $org->users()->attach($staff->id,['role'=>'member','status'=>'active','joined_at'=>now()]);
  return [$admin,$staff,$org];
 }
 public function test_only_authorised_organisation_manager_can_pre_register_staff_phone(): void {
  [$admin,$staff,$org]=$this->setupOrg();
  $this->actingAs($admin)->withSession(['active_organization_id'=>$org->id])
   ->postJson('/organization/staff-security/approved-phones',['user_id'=>$staff->id,'phone'=>'+254712345678'])
   ->assertCreated()->assertJsonPath('phone_last_four','5678');
  $this->assertDatabaseHas('organization_staff_approved_phones',['organization_id'=>$org->id,'user_id'=>$staff->id,'phone_e164'=>'+254712345678','status'=>'active']);
 }
 public function test_staff_cannot_self_register_an_approved_phone(): void {
  [$admin,$staff,$org]=$this->setupOrg();
  $this->actingAs($staff)->withSession(['active_organization_id'=>$org->id])
   ->postJson('/organization/staff-security/approved-phones',['user_id'=>$staff->id,'phone'=>'+254712345678'])
   ->assertForbidden();
  $this->assertDatabaseCount('organization_staff_approved_phones',0);
 }
 public function test_staff_without_pre_registered_number_cannot_request_otp(): void {
  [$admin,$staff,$org]=$this->setupOrg();
  $this->actingAs($staff)->withSession(['active_organization_id'=>$org->id])
   ->postJson('/organization/staff-security/otp/send',['purpose'=>'billing.approve'])
   ->assertForbidden();
 }
 public function test_otp_sender_uses_separate_nexora_provider_and_requires_https(): void {
  [$admin,$staff,$org]=$this->setupOrg();
  OrganizationStaffApprovedPhone::create(['organization_id'=>$org->id,'user_id'=>$staff->id,'phone_e164'=>'+254712345678','status'=>'active','approved_by'=>$admin->id,'approved_at'=>now()]);
  config(['services.nexora_staff_sms.url'=>'http://insecure.example.test/send']);
  $this->actingAs($staff)->withSession(['active_organization_id'=>$org->id])
   ->postJson('/organization/staff-security/otp/send',['purpose'=>'billing.approve'])
   ->assertStatus(503);
 }
}
