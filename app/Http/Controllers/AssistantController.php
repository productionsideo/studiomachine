<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Services\AssistantClaude;
use Illuminate\Http\Request;
use Throwable;

/**
 * Le réglage de l'assistant, et son banc d'essai.
 *
 * Le banc d'essai est là pour une raison précise : aucun réseau n'est encore
 * connecté, donc aucun vrai commentaire n'arrive. On peut malgré tout vérifier
 * — aujourd'hui, avant toute approbation de plateforme — que Claude classe
 * correctement et écrit dans le bon ton. On lui soumet un commentaire à la
 * main, il répond, rien n'est enregistré.
 *
 * C'est aussi le bon endroit pour se convaincre que la politique tient : tapez
 * « c'est combien ? » et regardez la réponse rester en brouillon.
 */
class AssistantController extends Controller
{
    use ResolvesClient;

    public function __construct(private AssistantClaude $assistant) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('dashboard.assistant', [
            'client'    => $this->resolveClient($request) ?? $user->client ?? Client::orderBy('name')->first(),
            'clients'   => $user->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'actif'     => $this->assistant->actif(),
            'modele'    => config('claude.modele'),
            'sensibles' => config('claude.sujets_sensibles'),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client->id);

        $data = $request->validate([
            'ai_context'   => ['nullable', 'string', 'max:8000'],
            'ai_signature' => ['nullable', 'string', 'max:120'],
        ]);

        $client->update([
            ...$data,
            'ai_auto_reply' => $request->boolean('ai_auto_reply'),
        ]);

        return back()->with('ok', 'Réglages enregistrés.');
    }

    /**
     * Soumet un commentaire fictif à Claude. Rien n'est écrit en base.
     * Le verdict d'envoi est recalculé par la même méthode que la production —
     * ce n'est pas une simulation, c'est le vrai chemin de décision.
     */
    public function essai(Request $request)
    {
        $data = $request->validate([
            'client_id'  => ['required', 'exists:clients,id'],
            'texte'      => ['required', 'string', 'max:2000'],
            'plateforme' => ['nullable', 'string', 'max:20'],
        ]);

        $this->authorizeClient($request, (int) $data['client_id']);

        $client = Client::findOrFail($data['client_id']);

        try {
            $a = $this->assistant->interroger(
                $client,
                $data['texte'],
                $data['plateforme'] ?? 'facebook',
            );

            $a['auto'] = $this->assistant->peutPartirSeul($client, $a);
        } catch (Throwable $e) {
            return back()
                ->withInput()
                ->with('erreur', 'Claude n\'a pas répondu : ' . $e->getMessage());
        }

        return back()->withInput()->with('essai', $a);
    }
}
