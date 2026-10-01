<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connexion des comptes tiers d'un client (Analytics et réseaux sociaux).
 *
 * Chaque plateforme a ses propres conditions d'accès, et trois d'entre elles
 * exigent une approbation externe qui ne dépend pas de nous. La page dit donc
 * l'état RÉEL de chaque réseau plutôt que d'afficher un bouton « Connecter »
 * qui ne mènerait nulle part.
 */
class IntegrationController extends Controller
{
    use ResolvesClient;

    /**
     * Ce que chaque plateforme exige réellement. Sert à afficher la marche à
     * suivre exacte, plutôt que de laisser l'utilisateur deviner pourquoi
     * « ça ne se connecte pas ».
     */
    public const PLATEFORMES = [
        'ga4' => [
            'nom'        => 'Google Analytics (GA4)',
            'mode'       => 'compte_service',
            'approbation'=> false,
            'donne'      => 'Trafic du site, sources, pages vues.',
            'exige'      => 'Un compte de service Google Cloud, ajouté en lecture sur la propriété GA4.',
            'delai'      => 'Immédiat — aucune approbation.',
        ],
        'youtube' => [
            'nom'        => 'YouTube',
            'mode'       => 'oauth',
            'approbation'=> true,
            'donne'      => 'Publication de vidéos et de Shorts, vues, durée d’écoute.',
            'exige'      => 'Un projet Google Cloud avec YouTube Data API v3, un écran de consentement vérifié, puis l’audit YouTube : sans lui, toute vidéo envoyée par l’API reste privée. Quota par défaut : 100 mises en ligne par jour pour toute l’agence.',
            'delai'      => 'Quelques jours à quelques semaines (vérification Google).',
        ],
        'facebook' => [
            'nom'        => 'Facebook',
            'mode'       => 'oauth',
            'approbation'=> true,
            'donne'      => 'Publication (texte, photos, vidéos, Reels), portée, vues, commentaires et réponses.',
            'exige'      => 'Une application Meta, la vérification d’entreprise, puis l’accès avancé aux permissions pages_manage_posts, publish_video, pages_read_engagement et pages_manage_engagement (démonstration vidéo à l’appui). Limite : 30 Reels publiés par API sur 24 h.',
            'delai'      => 'Plusieurs semaines — approbation Meta, sans garantie.',
        ],
        'instagram' => [
            'nom'        => 'Instagram',
            'mode'       => 'oauth',
            'approbation'=> true,
            'donne'      => 'Publication (Reels, images, carrousels, stories), vues, portée, commentaires et réponses.',
            'exige'      => 'Même application Meta. Le compte Instagram doit être Professionnel (Business ou Créateur) et rattaché à une Page Facebook. Permissions instagram_basic, instagram_content_publish, instagram_manage_comments et instagram_manage_insights à faire approuver. Limite : 100 publications par API sur 24 h.',
            'delai'      => 'Plusieurs semaines — approbation Meta, sans garantie.',
        ],
        'tiktok' => [
            'nom'        => 'TikTok',
            'mode'       => 'oauth',
            'approbation'=> true,
            'donne'      => 'Publication de vidéos, vues, mentions J’aime, partages.',
            'exige'      => 'Une application TikTok (Login Kit + Content Posting API), puis l’audit de TikTok : avant lui, les vidéos ne peuvent partir qu’en privé, sur au plus 5 comptes par jour.',
            'delai'      => 'Revue de l’app : quelques jours à deux semaines ; audit de publication en plus.',
        ],
    ];

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);

        if (! $client) {
            return redirect()->route('dashboard');
        }

        $this->authorizeClient($request, $client->id);

        return view('dashboard.integrations', [
            'client'       => $client,
            'clients'      => $request->user()->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'plateformes'  => self::PLATEFORMES,
            'branchees'    => $client->integrations()->get()->keyBy('platform'),
            // Une application ne peut être connectée que si ses identifiants
            // développeur existent côté serveur. Sans eux, le bouton mentirait.
            'configurees'  => $this->plateformesConfigurees(),
        ]);
    }

    /**
     * Enregistre les identifiants d'un compte de service (GA4).
     * Les jetons OAuth, eux, arriveront par le flux de redirection.
     */
    public function store(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client->id);

        $data = $request->validate([
            'platform'    => ['required', Rule::in(array_keys(self::PLATEFORMES))],
            'account_name'=> ['nullable', 'string', 'max:255'],
            'property_id' => ['nullable', 'string', 'max:60'],
            'credentials' => ['nullable', 'string', 'max:8000'],
        ]);

        // Le JSON du compte de service est validé avant d'être conservé :
        // un fichier tronqué produirait sinon une panne silencieuse au premier
        // appel d'API, des jours plus tard.
        if (! empty($data['credentials'])) {
            $json = json_decode($data['credentials'], true);

            if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                return back()->withErrors([
                    'credentials' => 'Ce fichier ne ressemble pas à une clé de compte de service Google (il doit contenir client_email et private_key).',
                ]);
            }
        }

        Integration::updateOrCreate(
            ['client_id' => $client->id, 'platform' => $data['platform']],
            [
                'account_name' => $data['account_name'] ?? null,
                'access_token' => $data['credentials'] ?? null,   // chiffré par le modèle
                'settings'     => array_filter(['property_id' => $data['property_id'] ?? null]),
                'active'       => true,
                'last_error'   => null,
            ]
        );

        return back()->with('ok', 'Compte enregistré.');
    }

    public function destroy(Request $request, Integration $integration)
    {
        $this->authorizeClient($request, $integration->client_id);

        $integration->delete();

        return back()->with('ok', 'Compte déconnecté.');
    }

    /**
     * Les plateformes dont les identifiants développeur sont présents dans la
     * configuration du serveur. Sans eux, aucun flux OAuth ne peut aboutir.
     */
    private function plateformesConfigurees(): array
    {
        $configurees = [];

        foreach (['youtube' => 'google', 'facebook' => 'meta', 'instagram' => 'meta', 'tiktok' => 'tiktok'] as $plateforme => $fournisseur) {
            if (config("services.{$fournisseur}.client_id")) {
                $configurees[] = $plateforme;
            }
        }

        // GA4 n'utilise pas OAuth mais un compte de service : il est toujours
        // branchable, sans identifiants développeur préalables.
        $configurees[] = 'ga4';

        return $configurees;
    }
}
