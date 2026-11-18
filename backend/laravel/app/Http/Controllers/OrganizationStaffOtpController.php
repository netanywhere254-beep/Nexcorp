<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationStaffApprovedPhone;
use App\Models\OrganizationStaffOtpChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class OrganizationStaffOtpController {
 private function org(Request $r): Organization { $o=$r->attributes->get('activeOrganization'); abort_unless($o instanceof Organization,409,'Select an active organisation.'); return $o; }
 public function approveNumber(Request $r): JsonResponse {
  $o=$this->org($r); abort_unless($r->user()->can('manageMembers',$o),403);
  $v=$r->validate(['user_id'=>['required','integer','exists:users,id'],'phone'=>['required','string','regex:/^\+[1-9][0-9]{7,14}$/']]);
  $staff=$o->users()->wherePivot('status','active')->where('users.id',$v['user_id'])->first();
  abort_unless($staff,422,'Staff user must already be an active member of this organisation.');
  $phone=$v['phone']; $old=OrganizationStaffApprovedPhone::where('organization_id',$o->id)->where('phone_e164',$phone)->first();
  abort_if($old&&$old->user_id!==$staff->id&&$old->status==='active',409,'Number already belongs to another staff member.');
  $p=OrganizationStaffApprovedPhone::updateOrCreate(['organization_id'=>$o->id,'user_id'=>$staff->id],['phone_e164'=>$phone,'status'=>'active','approved_by'=>$r->user()->id,'approved_at'=>now(),'revoked_at'=>null]);
  AuditLog::create(['organization_id'=>$o->id,'actor_user_id'=>$r->user()->id,'action'=>'organization.staff_phone.approved','subject_type'=>OrganizationStaffApprovedPhone::class,'subject_id'=>(string)$p->id,'metadata'=>['staff_user_id'=>$staff->id,'phone_last_four'=>substr($phone,-4)]]);
  return response()->json(['message'=>'Staff phone approved.','id'=>$p->id,'user_id'=>$staff->id,'phone_last_four'=>substr($phone,-4)],201);
 }
 public function revokeNumber(Request $r,int $id): JsonResponse {
  $o=$this->org($r); abort_unless($r->user()->can('manageMembers',$o),403);
  $p=OrganizationStaffApprovedPhone::where('organization_id',$o->id)->whereKey($id)->firstOrFail(); $p->update(['status'=>'revoked','revoked_at'=>now()]);
  OrganizationStaffOtpChallenge::where('approved_phone_id',$p->id)->whereNull('consumed_at')->update(['consumed_at'=>now()]);
  AuditLog::create(['organization_id'=>$o->id,'actor_user_id'=>$r->user()->id,'action'=>'organization.staff_phone.revoked','subject_type'=>OrganizationStaffApprovedPhone::class,'subject_id'=>(string)$p->id,'metadata'=>['staff_user_id'=>$p->user_id,'phone_last_four'=>substr($p->phone_e164,-4)]]);
  return response()->json(['message'=>'Staff phone revoked.']);
 }
 public function sendCode(Request $r): JsonResponse {
  $o=$this->org($r); $v=$r->validate(['purpose'=>['required','string','regex:/^[a-z][a-z0-9_.-]{2,99}$/']]);
  $p=OrganizationStaffApprovedPhone::where('organization_id',$o->id)->where('user_id',$r->user()->id)->where('status','active')->first(); abort_unless($p,403,'No organisation-approved staff phone is registered.');
  $recent=OrganizationStaffOtpChallenge::where('organization_id',$o->id)->where('user_id',$r->user()->id)->latest()->first(); abort_if($recent&&$recent->created_at->gt(now()->subSeconds(60)),429,'Wait before requesting another code.');
  $url=(string)config('services.nexora_staff_sms.url'); $token=(string)config('services.nexora_staff_sms.token'); abort_unless(Str::startsWith($url,'https://'),503,'Nexora staff SMS is not configured.');
  $code=(string)random_int(100000,999999); $http=Http::timeout(10)->acceptJson(); if($token!=='') $http=$http->withToken($token);
  $response=$http->post($url,['to'=>$p->phone_e164,'message'=>'Nexora staff confirmation code: '.$code.'. Expires in 5 minutes.']); abort_unless($response->successful(),502,'SMS provider did not accept the message.');
  $c=OrganizationStaffOtpChallenge::create(['organization_id'=>$o->id,'user_id'=>$r->user()->id,'approved_phone_id'=>$p->id,'purpose'=>$v['purpose'],'code_hash'=>Hash::make($code),'expires_at'=>now()->addMinutes(5)]);
  return response()->json(['message'=>'Code sent to approved staff number.','challenge_id'=>$c->id,'expires_in_seconds'=>300]);
 }
 public function verifyCode(Request $r): JsonResponse {
  $o=$this->org($r); $v=$r->validate(['challenge_id'=>['required','integer'],'code'=>['required','digits:6']]);
  $c=OrganizationStaffOtpChallenge::where('organization_id',$o->id)->where('user_id',$r->user()->id)->whereKey($v['challenge_id'])->whereNull('consumed_at')->firstOrFail();
  abort_if($c->verified_at,409,'Code already verified.'); abort_if($c->expires_at->isPast()||$c->attempts>=5,422,'Code expired or attempt limit reached.');
  if(!Hash::check($v['code'],$c->code_hash)){ $c->increment('attempts'); throw ValidationException::withMessages(['code'=>'Incorrect confirmation code.']); }
  abort_unless(OrganizationStaffApprovedPhone::whereKey($c->approved_phone_id)->where('status','active')->exists(),403,'Approved staff number was revoked.');
  $c->update(['verified_at'=>now()]); $r->session()->put('nexora_staff_otp',['challenge_id'=>$c->id,'organization_id'=>$o->id,'purpose'=>$c->purpose,'verified_at'=>now()->timestamp]);
  return response()->json(['message'=>'Staff confirmation verified.','purpose'=>$c->purpose,'valid_for_seconds'=>300]);
 }
}