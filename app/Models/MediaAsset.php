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

    public function surR2(): bool { return $this->disk === 'r2'; }

    /** Le fichier sur le disque du serveur (médias « local » seulement). */
    public function chemin(): string
    {
        return config('publication.medias.dossier') . '/' . $this->filename;
    }

    /**
     * Un chemin lisible sur le serveur, quel que soit le stockage. Un média
     * sur R2 est copié dans le cache le temps d'être envoyé à YouTube ou
     * TikTok (ils reçoivent le fichier lui-même, pas une adresse).
     */
    public function fichierLocal(): string
    {
        if (! $this->surR2()) {
            return $this->chemin();
        }

        $copie = config('publication.medias.cache') . '/' . $this->filename;

        if (! is_file($copie) || filesize($copie) !== (int) $this->size_bytes) {
            @mkdir(dirname($copie), 0775, true);
            app(\App\Services\R2::class)->telecharger($this->filename, $copie);
        }

        return $copie;
    }

    /** Le fichier existe-t-il ? (R2 n'est pas interrogé : on lui fait confiance.) */
    public function disponible(): bool
    {
        return $this->surR2() || is_file($this->chemin());
    }

    /** L'URL publique — celle que les plateformes viennent télécharger. */
    public function url(): string
    {
        return $this->adresse($this->filename);
    }

    public function vignetteUrl(): ?string
    {
        if (! $this->isVideo()) {
            return $this->url();
        }

        return $this->thumbnail ? $this->adresse($this->thumbnail) : null;
    }

    private function adresse(string $nom): string
    {
        return $this->surR2()
            ? \App\Services\R2::urlPublique($nom)
            : rtrim(config('publication.medias.url'), '/') . '/' . $nom;
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
