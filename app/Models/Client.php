<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'api_key_prefix', 'api_key_hash',
        'website', 'accent_color', 'logo_path', 'active',
        'ai_auto_reply', 'ai_context', 'ai_signature',
    ];

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return [
            'active'        => 'boolean',
            'ai_auto_reply' => 'boolean',
        ];
    }

    public function users()          { return $this->hasMany(User::class); }
    public function capsules()       { return $this->hasMany(Capsule::class); }
    public function events()         { return $this->hasMany(Event::class); }
    public function leads()          { return $this->hasMany(Lead::class); }
    public function integrations()   { return $this->hasMany(Integration::class); }
    public function socialComments() { return $this->hasMany(SocialComment::class); }

    /**
     * Fabrique une clé API, la stocke hachée et retourne la version en clair.
     * C'est le seul moment où la clé est lisible : elle n'est jamais
     * récupérable ensuite, il faut en générer une nouvelle.
     */
    public function generateApiKey(): string
    {
        $key = 'sm_' . Str::random(40);

        $this->api_key_prefix = substr($key, 0, 12);
        $this->api_key_hash   = hash('sha256', $key);
        $this->save();

        return $key;
    }

    /**
     * Retrouve le client correspondant à une clé API présentée.
     * Le préfixe cible la ligne, puis la comparaison se fait en temps constant
     * pour ne pas laisser fuiter d'information par le temps de réponse.
     */
    public static function findByApiKey(?string $key): ?self
    {
        if (! $key || ! str_starts_with($key, 'sm_')) {
            return null;
        }

        $client = static::where('api_key_prefix', substr($key, 0, 12))
            ->where('active', true)
            ->first();

        if (! $client) {
            return null;
        }

        return hash_equals($client->api_key_hash, hash('sha256', $key))
            ? $client
            : null;
    }
}
