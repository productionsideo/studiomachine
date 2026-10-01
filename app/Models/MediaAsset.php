<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'float'];
    }

    public function client() { return $this->belongsTo(Client::class); }
    public function posts()  { return $this->belongsToMany(Post::class, 'post_media'); }

    public function isVideo(): bool { return $this->kind === 'video'; }

    public function chemin(): string
    {
        return config('publication.medias.dossier') . '/' . $this->filename;
    }

    /** L'URL publique — celle que les plateformes viennent télécharger. */
    public function url(): string
    {
        return rtrim(config('publication.medias.url'), '/') . '/' . $this->filename;
    }

    public function vignetteUrl(): ?string
    {
        if (! $this->isVideo()) {
            return $this->url();
        }

        return $this->thumbnail
            ? rtrim(config('publication.medias.url'), '/') . '/' . $this->thumbnail
            : null;
    }

    /** 9:16, 1:1… — ce que les réseaux regardent avant d'accepter un fichier. */
    public function ratio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    public function estVertical(): bool
    {
        return ($this->ratio() ?? 1) < 0.8;
    }

    public function tailleLisible(): string
    {
        $mo = $this->size_bytes / 1024 / 1024;

        return $mo >= 1
            ? number_format($mo, 1, ',', ' ') . ' Mo'
            : number_format($this->size_bytes / 1024, 0, ',', ' ') . ' Ko';
    }
}
