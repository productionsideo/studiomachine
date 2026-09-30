<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function client()  { return $this->belongsTo(Client::class); }
    public function capsule() { return $this->belongsTo(Capsule::class); }

    /** Les statuts de suivi commercial, dans l'ordre du cycle de vente. */
    public const STATUSES = ['nouveau', 'contacte', 'qualifie', 'gagne', 'perdu'];

    /**
     * Les huit développements en cours de Construction CRD.
     *
     * La base ne garde que la clé : renommer un projet ici ne réécrit donc pas
     * l'historique. Si un jour d'autres clients ont leurs propres projets, cette
     * table passera en base — pour l'instant, un seul client en a.
     */
    public const PROJECTS = [
        'beauport-louis-xiv'     => 'Beauport — Domaine Louis-XIV',
        'beauport-st-ignace'     => 'Beauport — rang St-Ignace',
        'beauport-seigneuresses' => 'Beauport — rue des Seigneuresses',
        'charlesbourg-sherwood'  => 'Charlesbourg — rue Sherwood',
        'ste-brigitte-rivieres'  => 'Sainte-Brigitte-de-Laval — Domaine de la Rivière aux Pins',
        'ste-catherine-natura'   => 'Ste-Catherine-de-la-Jacques-Cartier — Boisé Natura',
        'st-jean-chrysostome'    => 'St-Jean-Chrysostome — rue de la Prairie',
        'st-joachim'             => 'St-Joachim',
        'mon-terrain'            => 'A déjà son terrain',
        'indecis'                => 'Ne sait pas encore',
    ];

    public const HOME_TYPES = [
        'unifamiliale' => 'Unifamiliale',
        'jumele'       => 'Jumelé ou en rangée',
        'condo'        => 'Condo ou superposé',
        'indecis'      => 'À déterminer',
    ];

    public const FINANCING = [
        'oui'      => 'Pré-approuvé',
        'en-cours' => 'En cours',
        'non'      => 'Pas encore',
        'aide'     => 'Souhaite être accompagné',
    ];

    public const BUDGETS = [
        'moins-300k'   => 'Moins de 300 000 $',
        '300-400k'     => '300 000 $ à 400 000 $',
        '400-500k'     => '400 000 $ à 500 000 $',
        '500-650k'     => '500 000 $ à 650 000 $',
        'plus-650k'    => 'Plus de 650 000 $',
        'a-determiner' => 'À déterminer',
    ];

    public const TIMELINES = [
        'des-que-possible' => 'Dès que possible',
        '6-mois'           => 'D’ici 6 mois',
        '1-an'             => 'D’ici 1 an',
        '2-ans'            => 'D’ici 2 ans',
        'exploration'      => 'S’informe seulement',
    ];

    /* Les libellés lisibles. Une clé inconnue (formulaire modifié depuis) est
       renvoyée telle quelle plutôt que masquée : mieux vaut afficher une valeur
       brute qu'une case vide qui laisserait croire à une donnée manquante. */

    public function getProjectLabelAttribute(): ?string
    {
        return $this->project ? (self::PROJECTS[$this->project] ?? $this->project) : null;
    }

    public function getHomeTypeLabelAttribute(): ?string
    {
        return $this->home_type ? (self::HOME_TYPES[$this->home_type] ?? $this->home_type) : null;
    }

    public function getFinancingLabelAttribute(): ?string
    {
        return $this->financing ? (self::FINANCING[$this->financing] ?? $this->financing) : null;
    }

    public function getBudgetLabelAttribute(): ?string
    {
        return $this->budget ? (self::BUDGETS[$this->budget] ?? $this->budget) : null;
    }

    public function getTimelineLabelAttribute(): ?string
    {
        return $this->timeline ? (self::TIMELINES[$this->timeline] ?? $this->timeline) : null;
    }

    public function getBedroomsLabelAttribute(): ?string
    {
        if (! $this->bedrooms) {
            return null;
        }

        return match ($this->bedrooms) {
            'indecis' => 'À déterminer',
            '5-plus'  => '5 ch. ou plus',
            default   => $this->bedrooms . ' ch.',
        };
    }

    public function scopeForClient($query, ?int $clientId)
    {
        return $clientId ? $query->where('client_id', $clientId) : $query;
    }
}
