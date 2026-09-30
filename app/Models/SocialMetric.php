<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMetric extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'collected_on'    => 'date',
            'completion_rate' => 'decimal:2',
        ];
    }

    public function capsulePost() { return $this->belongsTo(CapsulePost::class); }
}
