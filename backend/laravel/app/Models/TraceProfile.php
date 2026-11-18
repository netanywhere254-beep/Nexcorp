<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TraceProfile extends Model {
 protected $fillable=['country_code','region_code','locality','account_type','verification_status','phone_verification_status','location_verification_status','selfie_verification_status','selfie_path','verified_latitude','verified_longitude','submitted_latitude','submitted_longitude','verified_at'];
 protected function casts(): array { return ['verified_latitude'=>'decimal:7','verified_longitude'=>'decimal:7','submitted_latitude'=>'decimal:7','submitted_longitude'=>'decimal:7','verified_at'=>'datetime']; }
 public function user(): BelongsTo { return $this->belongsTo(User::class); }
}