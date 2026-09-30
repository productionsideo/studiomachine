<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\SocialComment;
use App\Services\AssistantClaude;
use App\Services\GraphMeta;
use Illuminate\Http\Request;

/**
 * Boîte de réception unique des commentaires reçus sur TikTok, Facebook,
 * Instagram et YouTube — et le poste de validation des réponses de Claude.
 *
 * Quatre états possibles pour une réponse :
 *
 *   aucune      → rien n'est parti. S'il y a un brouillon de Claude, il attend
 *                 une validation (sujet sensible, ou envoi auto désactivé).
 *   en_attente  → la réponse est validée et attend que la plateforme l'accepte.
 *   envoyee     → elle est publiée.
 *   ignoree     → on a décidé de ne pas répondre.
 *
 * Une réponse validée n'est jamais « perdue » : si l'API de la plateforme
 * refuse l'envoi (jeton expiré, quota), elle reste en file et l'échec est
 * visible — plutôt que de laisser croire qu'on a répondu alors que rien
 * n'est parti.
 */
class CommentController extends Controller
{
    use ResolvesClient;

    public function __construct(
        private AssistantClaude $assistant,
        private GraphMeta $graph,
    ) {}

    public function index(Request $request)
    {
        $user  = $request->user();
        $query = SocialComment::query()->with(['capsulePost.capsule', 'repliedBy']);

        if ($user->isAdmin()) {
            if ($client = $this->resolveClient($request)) {
                $query->where('client_id', $client->id);
            }
        } else {
            $query->where('client_id', $user->client_id);
        }

        if ($plateforme = $request->query('reseau')) {
            $query->where('platform', $plateforme);
        }

        // Par défaut : ce qui attend une réponse.
        if ($request->query('etat', 'a_repondre') === 'a_repondre') {
            $query->where('reply_status', 'aucune');
        }

        return view('dashboard.comments', [
            'comments'  => $query->latest('posted_at')->paginate(25)->withQueryString(),
            'client'    => $this->resolveClient($request),
            'clients'   => $user->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'branches'  => $this->connectedPlatforms($request),
            'assistant' => $this->assistant->actif(),
        ]);
    }

    /**
     * Demande à Claude de (re)lire un commentaire.
     *
     * Synchrone, à la demande : on clique, on attend deux secondes. Quand la
     * synchronisation automatique des plateformes existera, la même analyse
     * tournera en lot — voir la commande `assistant:analyser`.
     */
    public function suggest(Request $request, SocialComment $comment)
    {
        $this->authorizeClient($request, $comment->client_id);

        abort_unless($this->assistant->actif(), 409, 'Aucune clé Anthropic configurée.');

        $this->assistant->analyser($comment);

        $erreur = $comment->fresh()->ai_error;

        return $erreur
            ? back()->with('erreur', "Claude n'a pas pu lire ce commentaire : {$erreur}")
            : back()->with('ok', 'Claude a relu le commentaire.');
    }

    /**
     * Valide une réponse — celle de Claude, ou une version réécrite à la main.
     *
     * Le texte reçu fait foi : si la personne a corrigé le brouillon, c'est sa
     * version qui part, et la réponse lui est attribuée, pas à Claude.
     */
    public function reply(Request $request, SocialComment $comment)
    {
        $this->authorizeClient($request, $comment->client_id);

        $data = $request->validate([
            'reply_text' => ['required', 'string', 'max:2000'],
        ]);

        // On inscrit AVANT d'essayer d'envoyer. Si Meta est injoignable ou si le
        // processus meurt entre les deux, la réponse validée existe quand même :
        // elle repassera par `social:repondre`. L'ordre inverse — envoyer puis
        // inscrire — risquerait de publier une réponse dont on perdrait la trace.
        $comment->update([
            'reply_status'  => 'en_attente',
            'reply_text'    => $data['reply_text'],
            'replied_by'    => $request->user()->id,
            'replied_by_ai' => false,
            'reply_error'   => null,
        ]);

        // Facebook et Instagram partent tout de suite : la personne vient de
        // cliquer, elle mérite de savoir sur-le-champ si c'est passé. Les autres
        // réseaux n'ont pas encore de connecteur — la réponse reste en file, et
        // on le dit plutôt que de laisser croire qu'elle est partie.
        if (! in_array($comment->platform, ['facebook', 'instagram'], true)) {
            return back()->with('ok', "Réponse validée. Elle partira dès que {$comment->platform} sera connecté.");
        }

        [$ok, $resultat] = $this->graph->repondre($comment, $data['reply_text']);

        if (! $ok) {
            $comment->update(['reply_error' => $resultat]);

            return back()->with('erreur',
                "Réponse enregistrée, mais {$comment->platform} l'a refusée : {$resultat} " .
                'Elle reste en file et sera retentée automatiquement.');
        }

        $comment->update([
            'reply_status'      => 'envoyee',
            'replied_at'        => now(),
            'external_reply_id' => $resultat,
            'reply_error'       => null,
        ]);

        return back()->with('ok', 'Réponse publiée.');
    }

    /** Écarter un commentaire : pourriel, troll, ou simplement rien à dire. */
    public function ignore(Request $request, SocialComment $comment)
    {
        $this->authorizeClient($request, $comment->client_id);

        $comment->update(['reply_status' => 'ignoree']);

        return back()->with('ok', 'Commentaire écarté.');
    }

    /**
     * Les réseaux réellement connectés pour ce client.
     * Sert à afficher honnêtement ce qui est branché et ce qui ne l'est pas
     * encore, plutôt qu'une boîte vide qu'on prendrait pour « aucun commentaire ».
     */
    private function connectedPlatforms(Request $request): array
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return [];
        }

        return $client->integrations()
            ->where('active', true)
            ->pluck('platform')
            ->toArray();
    }
}
