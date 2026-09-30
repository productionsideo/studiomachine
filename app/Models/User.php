<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['client_id', 'name', 'email', 'password', 'role', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Membre de l'équipe Studio Machine : voit tous les clients. */
    public const ROLE_ADMIN = 'admin';

    /** Contact chez un client : ne voit que les données de son client. */
    public const ROLE_CLIENT = 'client';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'active'            => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Le client sur lequel les vues doivent être filtrées.
     * null pour un admin = aucune restriction (il voit tout).
     */
    public function scopeClientId(): ?int
    {
        return $this->isAdmin() ? null : $this->client_id;
    }

    /**
     * Un admin accède à tout ; un utilisateur client, uniquement au sien.
     * Tout le cloisonnement des données passe par cette méthode.
     */
    public function canAccessClient(int $clientId): bool
    {
        return $this->isAdmin() || $this->client_id === $clientId;
    }
}
