<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Gestion des accès à la plateforme — réservée à l'équipe Studio Machine.
 *
 * Deux rôles, et un seul mécanisme de cloisonnement :
 *
 *   admin  — membre de Studio Machine. client_id est NULL : il voit tous les
 *            clients, et peut créer d'autres accès.
 *   client — contact chez un client. client_id est renseigné, et TOUT le
 *            cloisonnement des données en découle (User::canAccessClient()).
 *
 * Les trois garde-fous ci-dessous existent parce qu'un tableau de bord dont on
 * se verrouille dehors n'est pas récupérable depuis l'interface — il faut aller
 * réparer en base, sur le serveur, à deux heures du matin :
 *
 *   1. On ne peut pas se désactiver ni se supprimer soi-même.
 *   2. On ne peut pas supprimer le dernier admin actif.
 *   3. Un admin n'a jamais de client_id, un client en a toujours un.
 *      Un « admin rattaché à un client » serait un rôle incohérent : les vues
 *      filtrent sur l'un OU sur l'autre, jamais sur les deux.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        return view('dashboard.users', [
            'admins'  => User::whereNull('client_id')->orderBy('name')->get(),
            'membres' => User::whereNotNull('client_id')->with('client')->orderBy('name')->get(),
            'clients' => Client::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'  => ['required', 'confirmed', Password::min(12)],
            'role'      => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_CLIENT])],
            // Obligatoire pour un accès client, interdit pour un admin.
            'client_id' => [
                Rule::requiredIf(fn () => $request->input('role') === User::ROLE_CLIENT),
                'nullable', 'exists:clients,id',
            ],
        ], [], [
            'client_id' => 'entreprise',
        ]);

        $admin = $data['role'] === User::ROLE_ADMIN;

        User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'],           // haché par le cast du modèle
            'role'      => $data['role'],
            'client_id' => $admin ? null : $data['client_id'],
            'active'    => true,
        ]);

        return back()->with('ok', $admin
            ? "{$data['name']} est maintenant administrateur."
            : "Accès créé pour {$data['email']}.");
    }

    /** Activer ou désactiver un accès, sans le détruire. */
    public function toggle(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('erreur', 'Vous ne pouvez pas désactiver votre propre accès.');
        }

        if ($user->active && $this->dernierAdminActif($user)) {
            return back()->with('erreur',
                'C’est le dernier administrateur actif. Le désactiver vous fermerait la porte à tous.');
        }

        $user->update(['active' => ! $user->active]);

        return back()->with('ok', $user->active
            ? "Accès de {$user->name} réactivé."
            : "Accès de {$user->name} suspendu.");
    }

    /** Réinitialiser le mot de passe de quelqu'un. */
    public function password(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user->update(['password' => $data['password']]);

        return back()->with('ok', "Mot de passe de {$user->name} remplacé.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('erreur', 'Vous ne pouvez pas supprimer votre propre accès.');
        }

        if ($this->dernierAdminActif($user)) {
            return back()->with('erreur', 'C’est le dernier administrateur actif : impossible de le supprimer.');
        }

        $nom = $user->name;
        $user->delete();

        return back()->with('ok', "Accès de {$nom} supprimé.");
    }

    /**
     * Cet utilisateur est-il le seul administrateur encore actif ?
     *
     * La question se pose AVANT de le désactiver ou de le supprimer. Si la
     * réponse est oui, l'opération laisserait la plateforme sans aucun compte
     * capable de créer un nouvel accès — et il faudrait aller réparer en base.
     */
    private function dernierAdminActif(User $user): bool
    {
        if (! $user->isAdmin() || ! $user->active) {
            return false;
        }

        return User::where('role', User::ROLE_ADMIN)
            ->where('active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }
}
