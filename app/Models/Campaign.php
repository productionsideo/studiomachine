<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'archived'  => 'boolean',
        ];
    }

    public function client() { return $this->belongsTo(Client::class); }
    public function posts()  { return $this->hasMany(Post::class); }

    /** Les demandes reçues dont le lien d'origine portait cette campagne. */
    public function leads()
    {
        return Lead::where('client_id', $this->client_id)
            ->where('utm_campaign', $this->utm_campaign);
    }
}
