<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TraceCaseDocument extends Model {
 protected $fillable=['trace_case_id','uploaded_by','document_type','storage_path','original_filename','mime_type','size_bytes','sha256','verification_status','review_notes'];
 public function traceCase(): BelongsTo { return $this->belongsTo(TraceCase::class); }
}