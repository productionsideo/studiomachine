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
            'archived'    => 'boolean',
            'codes_suivi' => 'array',
        ];
    }

    public function client() { return $this->belongsTo(Client::class); }
    public function posts()  { return $this->hasMany(Post::class); }

    /**
     * Tous les codes qui désignent cette campagne dans les liens : le sien, et
     * ceux des campagnes publicitaires qui lui sont rattachées (Meta, Metricool).
     */
    public function codes(): array
    {
        return array_values(array_unique(array_filter(array_merge([$this->utm_campaign], $this->codes_suivi ?? []))));
    }

    /** Les demandes reçues dont le lien d'origine portait un de ses codes. */
    public function leads()
    {
        return Lead::where('client_id', $this->client_id)
            ->whereIn('utm_campaign', $this->codes());
    }

    /**
     * Code → campagne qui le porte, chez un client (sauf $sauf).
     *
     * Une boucle, pas flatMap : un code tout en chiffres (identifiant Meta)
     * deviendrait une clé numérique que array_merge renumérote — perdue.
     *
     * @return array<string, Campaign>
     */
    public static function proprietaires(int $clientId, ?int $sauf = null): array
    {
        $carte = [];
        foreach (static::where('client_id', $clientId)->where('id', '!=', $sauf ?? 0)->get() as $c) {
            foreach ($c->codes() as $code) {
                $carte[(string) $code] = $c;
            }
        }

        return $carte;
    }

    /**
     * Code → id de campagne, pour un ensemble de campagnes : sert à ventiler
     * visites et demandes en une seule requête groupée.
     *
     * @return array<string, int>  "client_id|code" => campaign_id
     */
    public static function parCode(iterable $campagnes): array
    {
        $carte = [];
        foreach ($campagnes as $c) {
            foreach ($c->codes() as $code) {
                $carte["{$c->client_id}|{$code}"] = $c->id;
            }
        }

        return $carte;
    }
}
