<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public const STATUTS = [
        'brouillon' => 'Brouillon',
        'programmee'=> 'Programmée',
        'en_cours'  => 'En cours',
        'publiee'   => 'Publiée',
        'partielle' => 'En partie publiée',
        'echec'     => 'Échec',
    ];

    public function client()   { return $this->belongsTo(Client::class); }
    public function campaign() { return $this->belongsTo(Campaign::class); }
    public function auteur()   { return $this->belongsTo(User::class, 'created_by'); }
    public function targets()  { return $this->hasMany(PostTarget::class); }

    public function media()
    {
        return $this->belongsToMany(MediaAsset::class, 'post_media')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** Modifiable tant que rien n'est parti. Après, on ne réécrit pas l'histoire. */
    public function estModifiable(): bool
    {
        return in_array($this->status, ['brouillon', 'programmee'], true)
            && $this->targets->every(fn ($t) => $t->status === 'en_attente');
    }

    public function heureLocale(): ?\Illuminate\Support\Carbon
    {
        return $this->scheduled_at?->copy()->setTimezone(config('publication.fuseau'));
    }

    /**
     * Le lien à publier, marqué pour qu'une demande reçue puisse être
     * rattachée au réseau et à la campagne qui l'a amenée.
     */
    public function lienSuivi(string $plateforme): ?string
    {
        if (! $this->link_url) {
            return null;
        }

        $parametres = array_filter([
            'utm_source'   => $plateforme,
            'utm_medium'   => 'social',
            'utm_campaign' => $this->campaign?->utm_campaign,
            'utm_content'  => 'sm' . $this->id,
        ]);

        // Un lien qui porte déjà ses propres UTM est respecté tel quel.
        if (str_contains($this->link_url, 'utm_')) {
            return $this->link_url;
        }

        $separateur = str_contains($this->link_url, '?') ? '&' : '?';

        return $this->link_url . $separateur . http_build_query($parametres);
    }

    /**
     * Recalcule l'état d'ensemble à partir de celui des cibles. C'est la seule
     * façon de changer `status` une fois la publication lancée.
     */
    public function recalculerStatut(): void
    {
        if ($this->status === 'brouillon') {
            return;
        }

        $etats = $this->targets()->where('status', '!=', 'annulee')->pluck('status');

        $statut = match (true) {
            $etats->isEmpty()                                  => 'echec',
            $etats->every(fn ($e) => $e === 'publiee')         => 'publiee',
            $etats->every(fn ($e) => $e === 'en_attente')      => 'programmee',
            $etats->contains('en_cours') || $etats->contains('en_attente') => 'en_cours',
            $etats->contains('publiee')                        => 'partielle',
            default                                            => 'echec',
        };

        if ($statut !== $this->status) {
            $this->update(['status' => $statut]);
        }
    }
}
