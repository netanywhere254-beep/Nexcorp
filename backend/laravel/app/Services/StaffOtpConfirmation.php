<?php
namespace App\Services;
use App\Models\OrganizationStaffOtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
class StaffOtpConfirmation {
 public function consume(Request $request,string $purpose): void {
  $s=$request->session()->get('nexora_staff_otp');
  $orgId=(int)($request->attributes->get('activeOrganization')?->id ?? 0);
  if(!is_array($s)||($s['purpose']??null)!==$purpose||(int)($s['organization_id']??0)!==$orgId||(int)($s['verified_at']??0)<now()->subMinutes(5)->timestamp) throw new AuthorizationException('A fresh organisation-approved staff OTP is required.');
  DB::transaction(function() use($s) {
   $c=OrganizationStaffOtpChallenge::whereKey($s['challenge_id'])->lockForUpdate()->first();
   if(!$c||!$c->verified_at||$c->consumed_at||$c->expires_at->isPast()||$c->purpose!==$s['purpose']) throw new AuthorizationException('Staff OTP is invalid, expired, or already used.');
   $c->update(['consumed_at'=>now()]);
  });
  $request->session()->forget('nexora_staff_otp');
 }
}