<?php

namespace App\Services;

use Anthropic\Client as Anthropic;
use App\Models\Client;
use App\Models\SocialComment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L'assistant qui lit les commentaires et propose des réponses.
 *
 * ─── La règle qui structure tout ce fichier ───────────────────────────────
 *
 * Claude CLASSE et RÉDIGE. Il ne décide pas d'envoyer.
 *
 * La décision d'envoi est prise par peutPartirSeul(), en PHP, à partir de la
 * classification. On ne demande jamais au modèle « est-ce que tu peux publier
 * ça ? » : ce serait lui confier la garde de la règle qu'on veut lui imposer.
 * Un modèle qui se trompe de classification produit un brouillon inexact —
 * un modèle à qui on délègue la politique publie une bêtise.
 *
 * Concrètement : dès qu'un prix, un délai ou une disponibilité est en jeu,
 * un humain valide. CRD vend des maisons à 400 000 $ ; un chiffre lancé de
 * travers en commentaire public n'est pas un bogue, c'est un engagement.
 */
class AssistantClaude
{
    /** Les sujets que Claude sait reconnaître. Tout ajout ici doit aussi être
     *  décidé dans config/claude.php : sensible, ou pas. */
    public const SUJETS = [
        'prix'          => 'Prix, coût, budget, valeur',
        'delais'        => 'Délais, échéancier, date de livraison',
        'disponibilite' => 'Disponibilité : terrains, modèles, unités restantes',
        'terrain'       => 'Terrain, lot, zonage, localisation précise',
        'financement'   => 'Hypothèque, pré-approbation, programme d\'accès',
        'technique'     => 'Construction, matériaux, garantie, plans',
        'plainte'       => 'Insatisfaction, reproche, litige',
        'rendez_vous'   => 'Demande de rappel, de visite, de rencontre',
        'aucun'         => 'Aucun sujet engageant',
    ];

    public function __construct(private ?Anthropic $api = null)
    {
        if ($this->actif() && ! $this->api) {
            $this->api = new Anthropic(apiKey: config('claude.cle'));
        }
    }

    public function actif(): bool
    {
        return filled(config('claude.cle'));
    }

    /**
     * Analyse un commentaire et enregistre le résultat sur la ligne.
     * Ne lève jamais : un échec d'API n'est pas une raison de perdre un
     * commentaire. Il est consigné dans ai_error et reste à traiter à la main.
     */
    public function analyser(SocialComment $comment): SocialComment
    {
        if (! $this->actif()) {
            return $comment;
        }

        try {
            $a = $this->interroger($comment->client, (string) $comment->text, $comment->platform);

            $comment->update([
                'ai_category'    => $a['categorie'],
                'ai_topics'      => $a['sujets'],
                'ai_confidence'  => $a['certitude'],
                'ai_draft'       => $a['reponse'],
                'ai_note'        => mb_substr($a['note'], 0, 500),
                'ai_auto_ok'     => $this->peutPartirSeul($comment->client, $a),
                'ai_analyzed_at' => now(),
                'ai_model'       => config('claude.modele'),
                'ai_error'       => null,
            ]);

            // Le client a activé l'envoi automatique ET rien de sensible :
            // la réponse part en file d'attente sans passer par un humain.
            if ($comment->ai_auto_ok && $comment->reply_status === 'aucune' && filled($a['reponse'])) {
                $comment->update([
                    'reply_status'  => 'en_attente',
                    'reply_text'    => $a['reponse'],
                    'replied_by_ai' => true,
                    'replied_by'    => null,
                ]);
            }
        } catch (Throwable $e) {
            // On enregistre l'échec sans horodater l'analyse : la ligne reste
            // « à analyser », donc réessayable, tout en montrant pourquoi.
            $comment->update(['ai_error' => mb_substr($e->getMessage(), 0, 500)]);

            Log::warning('Assistant Claude — analyse impossible', [
                'commentaire' => $comment->id,
                'erreur'      => $e->getMessage(),
            ]);
        }

        return $comment->refresh();
    }

    /**
     * La politique « mixte », en clair et en un seul endroit.
     *
     * Il faut TOUT ce qui suit pour qu'une réponse parte sans humain :
     *   1. le client l'a explicitement activé ;
     *   2. le commentaire est anodin (un merci, un compliment, un emoji) ;
     *   3. Claude en est certain — un « moyenne » suffit à faire valider ;
     *   4. aucun sujet engageant n'a été détecté ;
     *   5. il y a effectivement une réponse à envoyer.
     *
     * Le doute joue toujours en faveur de l'humain.
     */
    public function peutPartirSeul(Client $client, array $a): bool
    {
        if (! $client->ai_auto_reply) {
            return false;
        }

        if ($a['categorie'] !== 'anodin' || $a['certitude'] !== 'haute') {
            return false;
        }

        $sensibles = config('claude.sujets_sensibles');

        if (array_intersect($a['sujets'], $sensibles) !== []) {
            return false;
        }

        return filled(trim($a['reponse']));
    }

    /**
     * Le modèle accepte-t-il la pensée adaptative et le réglage d'effort ?
     *
     * Ces deux paramètres sont apparus avec la génération 4.6. Les modèles
     * antérieurs — Haiku 4.5 notamment — les refusent par un HTTP 400. La liste
     * est explicite plutôt que devinée : mieux vaut qu'un modèle inconnu soit
     * traité comme ancien (appel simple, qui passe partout) que l'inverse.
     */
    private function modeleReflechit(string $modele): bool
    {
        foreach (['claude-opus-4-6', 'claude-opus-4-7', 'claude-opus-4-8',
                  'claude-sonnet-4-6', 'claude-sonnet-5', 'claude-fable-5'] as $recent) {
            if (str_starts_with($modele, $recent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un appel, une réponse validée contre un schéma.
     *
     * Les sorties structurées garantissent que la catégorie est bien l'une
     * des quatre attendues : la politique d'envoi s'appuie dessus, elle ne
     * peut pas reposer sur du texte à interpréter.
     */
    public function interroger(Client $client, string $texte, string $plateforme = 'facebook'): array
    {
        $modele = config('claude.modele');

        $arguments = [
            'model'        => $modele,
            'maxTokens'    => config('claude.jetons_max'),
            'system'       => $this->consignes($client),
            'outputConfig' => [
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
            'messages' => [[
                'role'    => 'user',
                'content' => "Commentaire reçu sur {$plateforme} :\n\n<<<\n{$texte}\n>>>",
            ]],
        ];

        // La pensée adaptative et le réglage d'effort n'existent que sur les
        // modèles récents. Les envoyer à Haiku 4.5 fait échouer l'appel avec un
        // 400 — pas un avertissement, un refus net. On ne les ajoute donc que
        // si le modèle configuré les comprend.
        //
        // Et pour ce travail-ci, on ne perd rien : classer un commentaire et
        // rédiger trois phrases ne demande pas de réflexion étendue. Le prix,
        // lui, est divisé par cinq.
        if ($this->modeleReflechit($modele)) {
            $arguments['thinking']               = ['type' => 'adaptive'];
            $arguments['outputConfig']['effort'] = config('claude.effort');
        }

        $reponse = $this->api->messages->create(...$arguments);

        // Avec la pensée adaptative, les blocs de réflexion précèdent le texte.
        // On ne prend que le bloc de texte : c'est lui qui porte le JSON.
        $json = null;
        foreach ($reponse->content as $bloc) {
            if ($bloc->type === 'text') {
                $json = json_decode($bloc->text, true);
                break;
            }
        }

        if (! is_array($json)) {
            throw new \RuntimeException('Réponse illisible de Claude.');
        }

        // Ceinture et bretelles : le schéma garantit déjà ces clés, mais la
        // politique d'envoi en dépend — on ne la laisse pas s'appuyer sur un
        // index manquant.
        return [
            'categorie' => $json['categorie'] ?? 'sensible',
            'sujets'    => array_values(array_filter((array) ($json['sujets'] ?? []))),
            'certitude' => $json['certitude'] ?? 'faible',
            'reponse'   => (string) ($json['reponse'] ?? ''),
            'note'      => (string) ($json['note'] ?? ''),
        ];
    }

    private function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'categorie' => [
                    'type' => 'string',
                    'enum' => ['anodin', 'sensible', 'negatif', 'pourriel'],
                    'description' => 'anodin : remerciement, compliment, emoji, réaction sans question. '
                        . 'sensible : toute question ou sous-entendu engageant l\'entreprise. '
                        . 'negatif : insatisfaction, reproche, attaque. '
                        . 'pourriel : publicité, hameçonnage, hors sujet total.',
                ],
                'sujets' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string', 'enum' => array_keys(self::SUJETS)],
                    'description' => 'Les sujets réellement abordés. « aucun » si le commentaire '
                        . 'n\'engage l\'entreprise sur rien.',
                ],
                'certitude' => [
                    'type' => 'string',
                    'enum' => ['haute', 'moyenne', 'faible'],
                    'description' => 'Le degré de certitude du classement. Au moindre doute : faible.',
                ],
                'reponse' => [
                    'type' => 'string',
                    'description' => 'La réponse proposée, en français québécois, prête à publier. '
                        . 'Chaîne vide si le commentaire ne mérite aucune réponse (pourriel).',
                ],
                'note' => [
                    'type' => 'string',
                    'description' => 'Une phrase expliquant le classement, pour la personne qui valide.',
                ],
            ],
            'required'             => ['categorie', 'sujets', 'certitude', 'reponse', 'note'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Les consignes. Elles disent au modèle ce qu'il est, et surtout ce qu'il
     * n'a pas le droit d'inventer — un chiffre inventé en commentaire public
     * est une promesse faite au nom du client.
     */
    private function consignes(Client $client): string
    {
        $contexte = trim((string) $client->ai_context) ?: 'Aucun contexte fourni.';
        $signature = trim((string) $client->ai_signature);
        $sujets = collect(self::SUJETS)
            ->map(fn ($d, $c) => "  - {$c} : {$d}")
            ->implode("\n");

        $consignes = <<<TXT
        Tu réponds aux commentaires publics sur les réseaux sociaux de {$client->name},
        au nom de l'entreprise. Tu écris en français québécois : naturel, chaleureux,
        vouvoiement, phrases courtes. Ni jargon, ni ton de communiqué de presse.

        # Ce que tu sais de l'entreprise
        {$contexte}

        # Ta tâche
        Pour chaque commentaire, tu produis deux choses :

        1. UN CLASSEMENT, qui sert à décider si un humain doit valider.
        2. UNE RÉPONSE rédigée, prête à publier.

        Sujets possibles :
        {$sujets}

        # Règle absolue : n'invente aucun fait
        Tu n'avances jamais un prix, un délai, une date de livraison, une
        disponibilité ou une caractéristique technique qui ne figure pas
        explicitement ci-dessus. Si l'information manque, tu ne la devines pas :
        tu invites la personne à écrire ou à téléphoner. Un chiffre inventé dans
        un commentaire public est un engagement pris au nom de l'entreprise.

        # Comment classer
        - « anodin » : un merci, un « superbe maison ! », un emoji, une réaction
          sans question ni sous-entendu. Ce sont les seuls commentaires auxquels
          une réponse peut partir sans relecture.
        - « sensible » : dès qu'il y a une question, une demande, ou un
          sous-entendu qui engage l'entreprise — même formulé gentiment.
          Dans le doute, c'est sensible.
        - « negatif » : insatisfaction, reproche, attaque. Ta réponse reconnaît
          le problème, ne se justifie pas, et ramène la conversation en privé.
        - « pourriel » : publicité, hameçonnage, hors sujet total. Réponse vide.

        Ta certitude est « haute » seulement si le classement ne fait aucun doute.

        # La réponse
        - 1 à 3 phrases. On est en commentaire, pas en lettre.
        - Aucune formule creuse (« nous vous remercions de votre intérêt »).
        - Une question ouverte se termine par une invitation à écrire ou appeler.
        - Pas de mot-clic, pas d'emoji sauf si le commentaire en contient.
        TXT;

        if ($signature) {
            $consignes .= "\n- Termine par : « {$signature} »";
        }

        return $consignes;
    }
}
