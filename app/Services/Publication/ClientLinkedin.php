<?php

namespace App\Services\Publication;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Appels à l'API versionnée de LinkedIn (https://api.linkedin.com/rest).
 *
 * Chaque appel doit porter l'en-tête Linkedin-Version (AAAAMM) : sans lui, ou
 * avec une version retirée, LinkedIn refuse tout. Une version vit au moins un
 * an ; LINKEDIN_VERSION se met à jour sans toucher au code.
 *
 * Comme ClientGraph pour Meta, une erreur dit si elle est passagère : le
 * publieur en a besoin pour décider s'il peut retenter sans doublon.
 */
class ClientLinkedin
{
    public const BASE = 'https://api.linkedin.com/rest';

    public function requete(string $jeton, int $delai = 30): PendingRequest
    {
        return Http::timeout($delai)
            ->withToken($jeton)
            ->withHeaders([
                'Linkedin-Version'          => (string) config('services.linkedin.version'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }

    /**
     * @throws ErreurReseau
     */
    public function appeler(string $jeton, string $verbe, string $chemin, array $corps = [], array $query = []): Response
    {
        try {
            $requete = $this->requete($jeton)->withQueryParameters($query);

            $reponse = $verbe === 'post'
                ? $requete->asJson()->post(self::BASE . $chemin, $corps)
                : $requete->get(self::BASE . $chemin);
        } catch (ConnectionException $e) {
            throw new ErreurReseau('LinkedIn injoignable : ' . $e->getMessage(), passagere: true);
        }

        if ($reponse->failed()) {
            throw new ErreurReseau(
                'LinkedIn a refusé : ' . $this->motif($reponse),
                passagere: $reponse->serverError() || $reponse->status() === 429,
                code: $reponse->status(),
            );
        }

        return $reponse;
    }

    public function motif(Response $reponse): string
    {
        $message = $reponse->json('message') ?? $reponse->json('error_description') ?? 'motif inconnu';
        $code    = $reponse->json('code') ?? $reponse->json('serviceErrorCode');

        return trim("{$message} (HTTP {$reponse->status()}" . ($code ? ", {$code}" : '') . ')');
    }

    /** Un URN dans un chemin d'URL doit être encodé (urn:li:video:… → urn%3Ali%3Avideo%3A…). */
    public static function urn(string $urn): string
    {
        return rawurlencode($urn);
    }
}
