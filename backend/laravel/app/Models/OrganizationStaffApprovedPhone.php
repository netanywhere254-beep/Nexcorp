<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrganizationStaffApprovedPhone extends Model {
 protected $fillable=['organization_id','user_id','phone_e164','status','approved_by','approved_at','revoked_at'];
 protected function casts(): array { return ['approved_at'=>'datetime','revoked_at'=>'datetime']; }
}