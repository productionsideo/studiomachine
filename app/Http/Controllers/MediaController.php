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

        return response()->json(['media' => $this->resume($media)]);
    }

    /**
     * Envoi direct d'une vidéo vers R2 : le serveur ouvre l'envoi et signe
     * une adresse par partie ; le navigateur dépose les parties lui-même.
     */
    public function debutDirect(Request $request, Client $client, Mediatheque $mediatheque)
    {
        $this->authorizeClient($request, $client->id);

        $d = $request->validate([
            'nom'    => ['required', 'string', 'max:255'],
            'taille' => ['required', 'integer', 'min:1'],
            'type'   => ['required', 'string', 'max:60'],
        ]);

        try {
            return response()->json($mediatheque->debuterEnvoiDirect(
                $client, $request->user(), $d['nom'], (int) $d['taille'], $d['type'],
            ));
        } catch (\Throwable $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }
    }

    public function finDirect(Request $request, Client $client, Mediatheque $mediatheque)
    {
        $this->authorizeClient($request, $client->id);

        $d = $request->validate([
            'envoi'    => ['required', 'string', 'max:1024'],
            'parts'    => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*'  => ['required', 'string', 'max:200'],
            'largeur'  => ['nullable', 'numeric'],
            'hauteur'  => ['nullable', 'numeric'],
            'duree'    => ['nullable', 'numeric'],
            'vignette' => ['nullable', 'file', 'max:4096'],
        ]);

        try {
            $media = $mediatheque->terminerEnvoiDirect(
                $client, $d['envoi'], $d['parts'], $d, $request->file('vignette'),
            );
        } catch (\Throwable $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['media' => $this->resume($media)]);
    }

    public function abandonDirect(Request $request, Client $client, Mediatheque $mediatheque)
    {
        $this->authorizeClient($request, $client->id);

        $mediatheque->abandonnerEnvoiDirect($client, (string) $request->input('envoi'));

        return response()->json(['ok' => true]);
    }

    private function resume(MediaAsset $media): array
    {
        return [
            'id'       => $media->id,
            'nom'      => $media->original_name,
            'genre'    => $media->kind,
            'vignette' => $media->vignetteUrl(),
            'duree'    => $media->duration_seconds,
            'largeur'  => $media->width,
            'hauteur'  => $media->height,
        ];
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
