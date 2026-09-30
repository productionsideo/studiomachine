<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapsulePost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function capsule()  { return $this->belongsTo(Capsule::class); }
    public function metrics()  { return $this->hasMany(SocialMetric::class); }
    public function comments() { return $this->hasMany(SocialComment::class); }

    /** Le dernier instantané de statistiques connu pour cette publication. */
    public function latestMetric()
    {
        return $this->hasOne(SocialMetric::class)->latestOfMany('collected_on');
    }

    public const PLATFORMS = ['tiktok', 'facebook', 'instagram', 'youtube'];
}
