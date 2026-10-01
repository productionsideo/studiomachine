<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare R2, par son API compatible S3.
 *
 * Pourquoi pas le SDK AWS
 * -----------------------
 * Le dossier vendor n'est jamais déployé : ajouter un paquet Composer voudrait
 * dire l'installer à la main sur le serveur. On n'utilise ici qu'une poignée
 * d'appels (envoi en plusieurs parties, dépôt, lecture, suppression) : la
 * signature AWS v4 tient en quelques fonctions, testées contre l'exemple
 * officiel d'AWS (tests/Unit/R2SignatureTest).
 *
 * Les vidéos ne passent jamais par le serveur : il crée l'envoi, signe une
 * adresse par partie, et le navigateur dépose chaque partie directement.
 */
class R2
{
    private const REGION  = 'auto';
    private const SERVICE = 's3';

    public static function actif(): bool
    {
        $c = config('publication.medias.r2');

        return filled($c['compte'] ?? null) && filled($c['cle'] ?? null) && filled($c['secret'] ?? null)
            && filled($c['bucket'] ?? null) && filled($c['url'] ?? null);
    }

    /** L'adresse publique d'un objet : celle que les réseaux viennent télécharger. */
    public static function urlPublique(string $cle): string
    {
        return rtrim((string) config('publication.medias.r2.url'), '/') . '/' . self::encoderChemin($cle);
    }

    // --- Envoi en plusieurs parties (depuis le navigateur) -----------------

    public function creerEnvoi(string $cle, string $mime): string
    {
        $r = $this->requete('POST', $cle, ['uploads' => ''], ['content-type' => $mime]);
        $this->exigerSucces($r, 'Création de l’envoi');

        $id = (string) (simplexml_load_string($r->body())->UploadId ?? '');
        if ($id === '') {
            throw new \RuntimeException('R2 n’a pas renvoyé d’identifiant d’envoi.');
        }

        return $id;
    }

    /** Une adresse signée où le navigateur dépose la partie n° $numero. */
    public function adressePartie(string $cle, string $envoi, int $numero, int $secondes = 43200): string
    {
        return $this->presigner('PUT', $cle, ['partNumber' => (string) $numero, 'uploadId' => $envoi], $secondes);
    }

    /** @param array<int, string> $parts numéro de partie => ETag */
    public function terminerEnvoi(string $cle, string $envoi, array $parts): void
    {
        ksort($parts);
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $numero => $etag) {
            $etag = '"' . trim($etag, '"') . '"';
            $xml .= '<Part><PartNumber>' . (int) $numero . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $r = $this->requete('POST', $cle, ['uploadId' => $envoi], ['content-type' => 'application/xml'], $xml);

        // S3 peut répondre 200 avec une erreur dans le corps.
        $this->exigerSucces($r, 'Assemblage du fichier');
        if (str_contains($r->body(), '<Error>')) {
            throw new \RuntimeException('Assemblage du fichier refusé par R2 : ' . strip_tags($r->body()));
        }
    }

    public function abandonnerEnvoi(string $cle, string $envoi): void
    {
        $this->requete('DELETE', $cle, ['uploadId' => $envoi]);
    }

    // --- Objets ------------------------------------------------------------

    /** Dépose un fichier du serveur (images, vignettes, import de campagne). */
    public function deposer(string $cle, string $chemin, string $mime): void
    {
        $flux = fopen($chemin, 'rb');

        try {
            $r = $this->requete('PUT', $cle, [], ['content-type' => $mime], $flux, timeout: 600);
        } finally {
            if (is_resource($flux)) {
                fclose($flux);
            }
        }

        $this->exigerSucces($r, 'Dépôt du fichier');
    }

    /** Taille et type d'un objet, ou null s'il n'existe pas. */
    public function entete(string $cle): ?array
    {
        $r = $this->requete('HEAD', $cle);

        if ($r->status() === 404) {
            return null;
        }
        $this->exigerSucces($r, 'Lecture du fichier');

        return ['taille' => (int) $r->header('Content-Length'), 'type' => $r->header('Content-Type')];
    }

    /** Les premiers octets d'un objet : de quoi reconnaître son vrai format. */
    public function debut(string $cle, int $octets = 65536): string
    {
        $r = $this->requete('GET', $cle, [], ['range' => 'bytes=0-' . ($octets - 1)]);
        $this->exigerSucces($r, 'Lecture du fichier');

        return $r->body();
    }

    /** Copie un objet sur le disque du serveur. */
    public function telecharger(string $cle, string $destination): void
    {
        $r = $this->requete('GET', $cle, [], [], null, timeout: 900, sink: $destination);

        if ($r->failed()) {
            @unlink($destination);
            $this->exigerSucces($r, 'Téléchargement du fichier');
        }
    }

    public function supprimer(string $cle): void
    {
        $r = $this->requete('DELETE', $cle);

        if ($r->failed() && $r->status() !== 404) {
            $this->exigerSucces($r, 'Suppression du fichier');
        }
    }

    // --- Signature AWS v4 --------------------------------------------------

    private function hote(): string
    {
        return config('publication.medias.r2.compte') . '.r2.cloudflarestorage.com';
    }

    private function chemin(string $cle): string
    {
        return '/' . config('publication.medias.r2.bucket') . '/' . self::encoderChemin($cle);
    }

    private function requete(
        string $methode,
        string $cle,
        array $query = [],
        array $entetes = [],
        mixed $corps = null,
        int $timeout = 60,
        ?string $sink = null,
    ): Response {
        $date  = gmdate('Ymd\THis\Z');
        $hote  = $this->hote();
        $chemin = $this->chemin($cle);

        $entetes = array_change_key_case($entetes) + [
            'host'                 => $hote,
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
            'x-amz-date'           => $date,
        ];

        $entetes['authorization'] = self::autorisation(
            $methode, $chemin, $query, $entetes, 'UNSIGNED-PAYLOAD', $date,
            config('publication.medias.r2.cle'), config('publication.medias.r2.secret'), self::REGION,
        );
        unset($entetes['host']);

        $url = 'https://' . $hote . $chemin . ($query ? '?' . self::requeteCanonique($query) : '');

        // Avec un corps, le type part par withBody() seulement : passé aussi
        // dans withHeaders(), Guzzle l'enverrait deux fois (« a, a ») et la
        // signature ne correspondrait plus.
        $type = $entetes['content-type'] ?? 'application/octet-stream';
        if ($corps !== null) {
            unset($entetes['content-type']);
        }

        $client = Http::timeout($timeout)->withHeaders($entetes);
        if ($sink) {
            $client = $client->sink($sink);
        }
        if ($corps !== null) {
            $client = $client->withBody($corps, $type);
        }

        return $client->send($methode, $url);
    }

    private function presigner(string $methode, string $cle, array $query, int $secondes): string
    {
        $date = gmdate('Ymd\THis\Z');

        return 'https://' . $this->hote() . $this->chemin($cle) . '?' . self::requeteCanonique(self::parametresPresignes(
            $methode, $this->hote(), $this->chemin($cle), $query, $secondes, $date,
            config('publication.medias.r2.cle'), config('publication.medias.r2.secret'), self::REGION,
        ));
    }

    /**
     * Les paramètres d'une adresse présignée (signature dans la requête).
     * Public et statique pour être vérifié contre l'exemple d'AWS.
     */
    public static function parametresPresignes(
        string $methode, string $hote, string $chemin, array $query, int $secondes,
        string $date, string $cle, string $secret, string $region,
    ): array {
        $portee = substr($date, 0, 8) . "/{$region}/" . self::SERVICE . '/aws4_request';

        $query += [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => "{$cle}/{$portee}",
            'X-Amz-Date'          => $date,
            'X-Amz-Expires'       => (string) $secondes,
            'X-Amz-SignedHeaders' => 'host',
        ];

        $canonique = implode("\n", [
            $methode, $chemin, self::requeteCanonique($query), "host:{$hote}\n", 'host', 'UNSIGNED-PAYLOAD',
        ]);

        $query['X-Amz-Signature'] = self::signer($canonique, $date, $secret, $region);

        return $query;
    }

    /** L'en-tête Authorization d'une requête signée par en-têtes. */
    public static function autorisation(
        string $methode, string $chemin, array $query, array $entetes, string $empreinte,
        string $date, string $cle, string $secret, string $region,
    ): string {
        $entetes = array_change_key_case($entetes);
        ksort($entetes);

        $signes    = implode(';', array_keys($entetes));
        $canonEnt  = '';
        foreach ($entetes as $nom => $valeur) {
            $canonEnt .= $nom . ':' . trim(preg_replace('/\s+/', ' ', (string) $valeur)) . "\n";
        }

        $canonique = implode("\n", [$methode, $chemin, self::requeteCanonique($query), $canonEnt, $signes, $empreinte]);
        $portee    = substr($date, 0, 8) . "/{$region}/" . self::SERVICE . '/aws4_request';

        return "AWS4-HMAC-SHA256 Credential={$cle}/{$portee}, SignedHeaders={$signes}, Signature="
            . self::signer($canonique, $date, $secret, $region);
    }

    private static function signer(string $canonique, string $date, string $secret, string $region): string
    {
        $jour   = substr($date, 0, 8);
        $portee = "{$jour}/{$region}/" . self::SERVICE . '/aws4_request';
        $texte  = "AWS4-HMAC-SHA256\n{$date}\n{$portee}\n" . hash('sha256', $canonique);

        $k = hash_hmac('sha256', $jour, 'AWS4' . $secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', self::SERVICE, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);

        return hash_hmac('sha256', $texte, $k);
    }

    public static function requeteCanonique(array $query): string
    {
        $paires = [];
        foreach ($query as $nom => $valeur) {
            $paires[rawurlencode((string) $nom)] = rawurlencode((string) $valeur);
        }
        ksort($paires, SORT_STRING);

        return implode('&', array_map(fn ($n, $v) => "{$n}={$v}", array_keys($paires), $paires));
    }

    private static function encoderChemin(string $cle): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $cle)));
    }

    private function exigerSucces(Response $r, string $etape): void
    {
        if ($r->successful()) {
            return;
        }

        $code    = $r->status();
        $message = trim((string) (@simplexml_load_string($r->body())->Message ?? ''));

        throw new \RuntimeException("{$etape} : R2 a répondu {$code}" . ($message !== '' ? " ({$message})" : '') . '.');
    }
}
