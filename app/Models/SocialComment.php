<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialComment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'posted_at'      => 'datetime',
            'replied_at'     => 'datetime',
            'hidden'         => 'boolean',
            'ai_topics'      => 'array',
            'ai_auto_ok'     => 'boolean',
            'ai_analyzed_at' => 'datetime',
            'replied_by_ai'  => 'boolean',
        ];
    }

    public function client()      { return $this->belongsTo(Client::class); }
    public function capsulePost() { return $this->belongsTo(CapsulePost::class); }
    public function repliedBy()   { return $this->belongsTo(User::class, 'replied_by'); }

    /** Les sujets engageants détectés — ceux qui imposent une validation humaine. */
    public function sujetsSensibles(): array
    {
        return array_intersect($this->ai_topics ?? [], config('claude.sujets_sensibles'));
    }

    /** Claude a une réponse à proposer, et personne ne l'a encore traitée. */
    public function aUnBrouillon(): bool
    {
        return $this->reply_status === 'aucune' && filled($this->ai_draft);
    }

    /** Qui a répondu : une personne, Claude, ou personne encore. */
    public function auteurReponse(): ?string
    {
        return match (true) {
            $this->reply_status === 'aucune' => null,
            $this->replied_by_ai             => 'Claude',
            default                          => $this->repliedBy?->name ?? 'Équipe',
        };
    }
}
