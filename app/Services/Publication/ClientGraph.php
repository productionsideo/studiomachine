<?php

namespace App\Services\Publication;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Appels à l'API Graph de Meta pour la PUBLICATION.
 *
 * Distinct de GraphMeta (lecture des commentaires) pour une raison : ici, une
 * erreur doit dire si elle est passagère. GraphMeta peut se contenter de
 * « ça a échoué, on réessaiera dans 15 minutes » ; un publieur, lui, doit
 * savoir s'il a le droit de retenter sans risquer un doublon.
 */
class ClientGraph
{
    /**
     * Codes que Meta documente comme temporaires : limite de débit, service
     * momentanément indisponible. Tout le reste est traité comme un refus.
     */
    private const CODES_PASSAGERS = [1, 2, 4, 17, 32, 341, 368, 613];

    public function base(): string
    {
        return 'https://graph.facebook.com/' . config('meta.version');
    }

    /**
     * @throws ErreurReseau
     */
    public function appeler(string $chemin, string $jeton, array $params = [], string $verbe = 'get'): array
    {
        $params['access_token'] = $jeton;

        try {
            $requete = Http::timeout(60);

            $reponse = $verbe === 'post'
                ? $requete->asForm()->post($this->base() . $chemin, $params)
                : $requete->get($this->base() . $chemin, $params);
        } catch (ConnectionException $e) {
            throw new ErreurReseau('Meta injoignable : ' . $e->getMessage(), passagere: true);
        }

        $corps = $reponse->json() ?? [];

        if (isset($corps['error'])) {
            $e = $corps['error'];

            throw new ErreurReseau(
                sprintf(
                    'Meta a refusé : %s (code %s%s)',
                    $e['error_user_msg'] ?? $e['message'] ?? 'motif inconnu',
                    $e['code'] ?? '?',
                    isset($e['error_subcode']) ? ', sous-code ' . $e['error_subcode'] : '',
                ),
                passagere: in_array((int) ($e['code'] ?? 0), self::CODES_PASSAGERS, true)
                    || ! empty($e['is_transient']),
                code: (int) ($e['code'] ?? 0),
                sousCode: (int) ($e['error_subcode'] ?? 0),
            );
        }

        if ($reponse->failed()) {
            throw new ErreurReseau("Meta a répondu HTTP {$reponse->status()}.", passagere: $reponse->serverError());
        }

        return $corps;
    }
}
