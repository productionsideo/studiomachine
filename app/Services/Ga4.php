<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lecture de Google Analytics 4 par un compte de service.
 *
 * Pas de bibliothèque Google : un compte de service s'authentifie en signant
 * lui-même un jeton JWT (RS256) avec sa clé privée, que Google échange contre
 * un jeton d'accès d'une heure. OpenSSL suffit, et on évite d'ajouter une
 * dépendance de plusieurs mégaoctets pour deux requêtes.
 *
 * La clé (le JSON complet) vit chiffrée dans integrations.access_token ; elle
 * n'est déchiffrée qu'ici, le temps de signer.
 */
class Ga4
{
    private const PORTEE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const API    = 'https://analyticsdata.googleapis.com/v1beta';

    /**
     * Exécute un rapport (runReport) et rend toutes ses lignes, pages
     * comprises, sous forme de tableaux associatifs nom => valeur.
     */
    public function rapport(Integration $integration, array $corps): array
    {
        $propriete = $integration->settings['property_id'] ?? null;

        if (! $propriete || ! ctype_digit((string) $propriete)) {
            throw new \RuntimeException('Identifiant de propriété GA4 absent ou invalide (9 à 10 chiffres, pas le G-…).');
        }

        $lignes  = [];
        $decalage = 0;

        do {
            $reponse = Http::timeout(60)
                ->withToken($this->jeton($integration))
                ->post(self::API . "/properties/{$propriete}:runReport", $corps + ['limit' => 10000, 'offset' => $decalage]);

            if ($reponse->failed()) {
                throw new \RuntimeException($this->expliquer($reponse->status(), $reponse->json('error.message', ''), $integration));
            }

            $dims = array_column($reponse->json('dimensionHeaders', []), 'name');
            $mets = array_column($reponse->json('metricHeaders', []), 'name');

            foreach ($reponse->json('rows', []) as $r) {
                $ligne = [];
                foreach ($dims as $i => $nom) {
                    $ligne[$nom] = $r['dimensionValues'][$i]['value'] ?? '';
                }
                foreach ($mets as $i => $nom) {
                    $ligne[$nom] = $r['metricValues'][$i]['value'] ?? '0';
                }
                $lignes[] = $ligne;
            }

            $total    = (int) $reponse->json('rowCount', 0);
            $decalage += 10000;
        } while ($decalage < $total);

        return $lignes;
    }

    /** Un jeton d'accès, gardé 50 minutes (Google le donne pour 60). */
    private function jeton(Integration $integration): string
    {
        return Cache::remember("ga4-jeton-{$integration->id}-" . md5((string) $integration->access_token), 3000, function () use ($integration) {
            $cle = json_decode((string) $integration->access_token, true);

            if (empty($cle['client_email']) || empty($cle['private_key'])) {
                throw new \RuntimeException('La clé du compte de service est absente ou incomplète. Recollez le fichier JSON dans Intégrations.');
            }

            $url = $cle['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $maintenant = time();

            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $entete = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $charge = $b64(json_encode([
                'iss'   => $cle['client_email'],
                'scope' => self::PORTEE,
                'aud'   => $url,
                'iat'   => $maintenant,
                'exp'   => $maintenant + 3600,
            ]));

            if (! openssl_sign("{$entete}.{$charge}", $signature, $cle['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Clé privée illisible : le fichier JSON a peut-être été tronqué en le collant.');
            }

            $reponse = Http::asForm()->timeout(20)->post($url, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "{$entete}.{$charge}." . $b64($signature),
            ]);

            if (! $reponse->json('access_token')) {
                // invalid_grant : la clé a été supprimée ou révoquée côté Google.
                throw new \RuntimeException('Google refuse la clé du compte de service (' . $reponse->json('error_description', $reponse->json('error', 'motif inconnu'))
                    . '). Si la clé a été supprimée dans Google Cloud, créez-en une nouvelle et recollez-la.');
            }

            return $reponse->json('access_token');
        });
    }

    private function expliquer(int $statut, string $message, Integration $integration): string
    {
        $courriel = json_decode((string) $integration->access_token, true)['client_email'] ?? 'le compte de service';

        return match (true) {
            $statut === 403 && str_contains($message, 'has not been used') =>
                'L’API « Google Analytics Data » n’est pas activée dans le projet Google Cloud. Activez-la, puis réessayez dans quelques minutes.',
            $statut === 403 =>
                "Accès refusé à la propriété. Dans GA4 : Admin → Gestion des accès à la propriété, ajoutez {$courriel} avec le rôle Lecteur.",
            $statut === 404 || ($statut === 400 && str_contains($message, 'property')) =>
                'Propriété GA4 introuvable : vérifiez l’identifiant (Admin → Détails de la propriété, 9 à 10 chiffres).',
            $statut === 429 =>
                'Quota GA4 dépassé pour l’heure ; la prochaine collecte réessaiera.',
            default => "GA4 a répondu HTTP {$statut} : {$message}",
        };
    }
}
