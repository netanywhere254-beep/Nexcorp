<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrganizationStaffOtpChallenge extends Model {
 protected $fillable=['organization_id','user_id','approved_phone_id','purpose','code_hash','attempts','expires_at','verified_at','consumed_at'];
 protected function casts(): array { return ['expires_at'=>'datetime','verified_at'=>'datetime','consumed_at'=>'datetime']; }
}