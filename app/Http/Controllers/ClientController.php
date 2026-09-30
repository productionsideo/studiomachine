<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestion des clients de la plateforme — réservé à l'équipe Studio Machine.
 * C'est ici qu'on ajoute un client, qu'on lui génère sa clé API et qu'on
 * ouvre des accès à ses contacts.
 */
class ClientController extends Controller
{
    public function index()
    {
        return view('dashboard.clients', [
            'clients' => Client::withCount(['leads', 'users'])->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('dashboard.client-form', ['client' => new Client()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'slug'         => ['required', 'string', 'max:60', 'unique:clients,slug', 'regex:/^[a-z0-9-]+$/'],
            'website'      => ['nullable', 'url', 'max:255'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $client = new Client([
            ...$data,
            'accent_color' => $data['accent_color'] ?? '#9A0F20',
            'active'       => true,
        ]);

        // Valeurs provisoires : generateApiKey() les remplace aussitôt.
        $client->api_key_prefix = Str::random(12);
        $client->api_key_hash   = '';
        $client->save();

        $cle = $client->generateApiKey();

        // La clé n'est lisible qu'ici, une seule fois. On la passe en session
        // flash plutôt qu'en base : elle ne doit exister nulle part en clair.
        return redirect()
            ->route('clients.edit', $client)
            ->with('nouvelle_cle', $cle)
            ->with('ok', "Client « {$client->name} » créé.");
    }

    public function edit(Client $client)
    {
        return view('dashboard.client-form', [
            'client' => $client->load('users'),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'slug'         => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('clients')->ignore($client)],
            'website'      => ['nullable', 'url', 'max:255'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active'       => ['boolean'],
        ]);

        $client->update([...$data, 'active' => $request->boolean('active')]);

        return back()->with('ok', 'Client mis à jour.');
    }

    /**
     * Révoque la clé actuelle et en émet une nouvelle.
     * Le site client cesse d'émettre tant que sa configuration n'est pas
     * mise à jour — c'est voulu : c'est le geste à poser si une clé fuit.
     */
    public function regenerateKey(Client $client)
    {
        $cle = $client->generateApiKey();

        return back()
            ->with('nouvelle_cle', $cle)
            ->with('ok', 'Nouvelle clé API émise. L\'ancienne ne fonctionne plus.');
    }

    /** Ouvre un accès au dashboard à un contact chez le client. */
    public function addUser(Request $request, Client $client)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        User::create([
            'client_id' => $client->id,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'],   // haché par le cast du modèle
            'role'      => User::ROLE_CLIENT,
            'active'    => true,
        ]);

        return back()->with('ok', "Accès créé pour {$data['email']}.");
    }
}
