<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    /** La table n'a que created_at : les événements ne sont jamais modifiés. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function client()  { return $this->belongsTo(Client::class); }
    public function capsule() { return $this->belongsTo(Capsule::class); }

    public const TYPES = ['pageview', 'form_start', 'step', 'submit', 'abandon'];
}
