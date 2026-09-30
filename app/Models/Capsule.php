<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Capsule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function client() { return $this->belongsTo(Client::class); }
    public function posts()  { return $this->hasMany(CapsulePost::class); }
    public function events() { return $this->hasMany(Event::class); }
    public function leads()  { return $this->hasMany(Lead::class); }
}
