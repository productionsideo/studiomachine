<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Services\Mediatheque;
use Illuminate\Http\Request;

/**
 * La médiathèque d'un client : ce qu'on peut publier.
 */
class MediaController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return view('publication.choisir-client', [
                'clients' => Client::where('active', true)->orderBy('name')->get(),
                'suite'   => 'medias.index',
            ]);
        }

        $this->authorizeClient($request, $client->id);

        return view('publication.medias', [
            'client' => $client,
            'medias' => $client->mediaAssets()->withCount('posts')->latest()->paginate(48)->withQueryString(),
        ]);
    }

    /**
     * Reçoit un morceau de fichier. Répond en JSON : c'est le script de
     * l'envoi qui appelle, morceau après morceau.
     */
    public function morceau(Request $request, Client $client, Mediatheque $mediatheque)
    {
        $this->authorizeClient($request, $client->id);

        $d = $request->validate([
            'envoi'   => ['required', 'string'],
            'index'   => ['required', 'integer', 'min:0'],
            'total'   => ['required', 'integer', 'min:1', 'max:200'],
            'nom'     => ['required', 'string', 'max:255'],
            'morceau' => ['required', 'file'],
        ]);

        try {
            $media = $mediatheque->recevoirMorceau(
                $client, $request->user(), $d['envoi'], (int) $d['index'], (int) $d['total'], $d['nom'], $request->file('morceau'),
            );
        } catch (\Throwable $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        if (! $media) {
            return response()->json(['suite' => true]);
        }

        return response()->json(['media' => [
            'id'       => $media->id,
            'nom'      => $media->original_name,
            'genre'    => $media->kind,
            'vignette' => $media->vignetteUrl(),
            'duree'    => $media->duration_seconds,
            'largeur'  => $media->width,
            'hauteur'  => $media->height,
        ]]);
    }

    public function destroy(Request $request, MediaAsset $media, Mediatheque $mediatheque)
    {
        $this->authorizeClient($request, $media->client_id);

        try {
            $mediatheque->supprimer($media);
        } catch (\RuntimeException $e) {
            return back()->with('ok', $e->getMessage());
        }

        return back()->with('ok', 'Média supprimé.');
    }
}
