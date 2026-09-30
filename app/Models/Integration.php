<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            // Chiffrés au repos : la clé vit dans APP_KEY, jamais en base.
            'access_token'     => 'encrypted',
            'refresh_token'    => 'encrypted',
            'settings'         => 'array',
            'token_expires_at' => 'datetime',
            'last_synced_at'   => 'datetime',
            'active'           => 'boolean',
        ];
    }

    public function client() { return $this->belongsTo(Client::class); }

    public const PLATFORMS = ['ga4', 'youtube', 'facebook', 'instagram', 'tiktok'];

    public function isExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
