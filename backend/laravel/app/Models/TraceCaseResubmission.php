<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TraceCaseResubmission extends Model
{
    protected $fillable = ['trace_case_id', 'submitted_by', 'reason', 'previous_review'];

    protected function casts(): array
    {
        return ['previous_review' => 'array'];
    }
}
