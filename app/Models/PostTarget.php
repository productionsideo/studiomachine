<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostTarget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options'         => 'array',
            'next_attempt_at' => 'datetime',
            'started_at'      => 'datetime',
            'published_at'    => 'datetime',
        ];
    }

    public const STATUTS = [
        'en_attente' => 'En attente',
        'en_cours'   => 'En traitement',
        'publiee'    => 'Publiée',
        'echec'      => 'Échec',
        'annulee'    => 'Annulée',
    ];

    public function post()        { return $this->belongsTo(Post::class); }
    public function integration() { return $this->belongsTo(Integration::class); }

    /** Le texte réellement publié sur ce réseau. */
    public function texte(): string
    {
        $texte = trim($this->caption_override ?? '') !== ''
            ? $this->caption_override
            : (string) $this->post->caption;

        $lien = $this->post->lienSuivi($this->platform);

        // Instagram et TikTok ne rendent pas les liens cliquables dans la
        // légende : on ne les y met pas, ils feraient du bruit pour rien.
        if ($lien && in_array($this->platform, ['facebook', 'youtube'], true)) {
            $texte = rtrim($texte) . "\n\n" . $lien;
        }

        return $texte;
    }

    public function option(string $cle, mixed $defaut = null): mixed
    {
        return $this->options[$cle] ?? $defaut;
    }
}
