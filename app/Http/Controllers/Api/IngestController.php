<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Capsule;
use App\Models\Client;
use App\Models\Event;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Point d'entrée des données envoyées par les sites clients.
 *
 * Le client_id vient TOUJOURS de la clé API (posée sur la requête par le
 * middleware), jamais du corps de la requête : c'est ce qui empêche un site
 * client d'écrire dans les données d'un autre.
 *
 * Les adresses IP n'arrivent ici que déjà hachées par le site émetteur —
 * la plateforme ne détient donc aucune IP en clair (Loi 25).
 */
class IngestController extends Controller
{
    /**
     * Reçoit un lot d'événements de parcours.
     * Le lot évite un appel réseau par clic depuis le site client.
     */
    public function events(Request $request): JsonResponse
    {
        $client = $request->attributes->get('client');

        $data = $request->validate([
            'events'                 => ['required', 'array', 'max:50'],
            'events.*.type'          => ['required', Rule::in(Event::TYPES)],
            'events.*.session_id'    => ['required', 'string', 'max:64'],
            'events.*.capsule'       => ['nullable', 'integer', 'min:1', 'max:9999'],
            'events.*.platform'      => ['nullable', 'string', 'max:20'],
            'events.*.step'          => ['nullable', 'integer', 'min:0', 'max:255'],
            'events.*.page'          => ['nullable', 'string', 'max:500'],
            'events.*.device'        => ['nullable', 'string', 'max:20'],
            'events.*.os'            => ['nullable', 'string', 'max:40'],
            'events.*.browser'       => ['nullable', 'string', 'max:40'],
            'events.*.referrer'      => ['nullable', 'string', 'max:500'],
            'events.*.country'       => ['nullable', 'string', 'max:2'],
            'events.*.utm_source'    => ['nullable', 'string', 'max:100'],
            'events.*.utm_medium'    => ['nullable', 'string', 'max:100'],
            'events.*.utm_campaign'  => ['nullable', 'string', 'max:100'],
            'events.*.utm_content'   => ['nullable', 'string', 'max:100'],
            'events.*.utm_term'      => ['nullable', 'string', 'max:100'],
            'events.*.ip_hash'       => ['nullable', 'string', 'max:64'],
            'events.*.meta'          => ['nullable', 'array'],
            'events.*.occurred_at'   => ['nullable', 'date'],
        ]);

        $rows = [];

        foreach ($data['events'] as $e) {
            $rows[] = [
                'client_id'    => $client->id,
                'capsule_id'   => $this->resolveCapsuleId($client, $e['capsule'] ?? null),
                'platform'     => $e['platform'] ?? null,
                'session_id'   => $e['session_id'],
                'type'         => $e['type'],
                'step'         => $e['step'] ?? null,
                'page'         => $e['page'] ?? null,
                'device'       => $e['device'] ?? null,
                'os'           => $e['os'] ?? null,
                'browser'      => $e['browser'] ?? null,
                'referrer'     => $e['referrer'] ?? null,
                'country'      => $e['country'] ?? null,
                'utm_source'   => $e['utm_source'] ?? null,
                'utm_medium'   => $e['utm_medium'] ?? null,
                'utm_campaign' => $e['utm_campaign'] ?? null,
                'utm_content'  => $e['utm_content'] ?? null,
                'utm_term'     => $e['utm_term'] ?? null,
                'ip_hash'      => $e['ip_hash'] ?? null,
                'meta'         => isset($e['meta']) ? json_encode($e['meta']) : null,
                'created_at'   => $e['occurred_at'] ?? now(),
            ];
        }

        DB::table('events')->insert($rows);

        return response()->json([
            'ok'       => true,
            'received' => count($rows),
        ]);
    }

    /**
     * Reçoit une demande complétée.
     *
     * L'écriture est idempotente sur (client_id, external_id) : si le site
     * client renvoie un lead parce qu'il n'a pas reçu notre accusé de
     * réception, on met à jour au lieu de créer un doublon.
     */
    public function lead(Request $request): JsonResponse
    {
        $client = $request->attributes->get('client');

        $data = $request->validate([
            'external_id'  => ['required', 'string', 'max:64'],
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'capsule'      => ['nullable', 'integer', 'min:1', 'max:9999'],
            'platform'     => ['nullable', 'string', 'max:20'],
            'session_id'   => ['nullable', 'string', 'max:64'],
            'project'      => ['nullable', 'string', 'max:40'],
            'has_land'     => ['nullable', 'string', 'max:30'],
            'home_type'    => ['nullable', 'string', 'max:30'],
            'bedrooms'     => ['nullable', 'string', 'max:10'],
            'sector'       => ['nullable', 'string', 'max:120'],
            'budget'       => ['nullable', 'string', 'max:40'],
            'financing'    => ['nullable', 'string', 'max:20'],
            'timeline'     => ['nullable', 'string', 'max:40'],
            'message'      => ['nullable', 'string', 'max:5000'],
            'utm_source'   => ['nullable', 'string', 'max:100'],
            'utm_medium'   => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
            'utm_content'  => ['nullable', 'string', 'max:100'],
            'utm_term'     => ['nullable', 'string', 'max:100'],
            'device'       => ['nullable', 'string', 'max:20'],
            'referrer'     => ['nullable', 'string', 'max:500'],
            'ip_hash'      => ['nullable', 'string', 'max:64'],
            'locale'       => ['nullable', 'string', 'max:5'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $capsuleId = $this->resolveCapsuleId($client, $data['capsule'] ?? null);

        $lead = Lead::updateOrCreate(
            [
                'client_id'   => $client->id,
                'external_id' => $data['external_id'],
            ],
            [
                'capsule_id'   => $capsuleId,
                'platform'     => $data['platform'] ?? null,
                'session_id'   => $data['session_id'] ?? null,
                'name'         => $data['name'],
                'email'        => $data['email'],
                'phone'        => $data['phone'] ?? null,
                'project'      => $data['project'] ?? null,
                'has_land'     => $data['has_land'] ?? null,
                'home_type'    => $data['home_type'] ?? null,
                'bedrooms'     => $data['bedrooms'] ?? null,
                'sector'       => $data['sector'] ?? null,
                'budget'       => $data['budget'] ?? null,
                'financing'    => $data['financing'] ?? null,
                'timeline'     => $data['timeline'] ?? null,
                'message'      => $data['message'] ?? null,
                'utm_source'   => $data['utm_source'] ?? null,
                'utm_medium'   => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'utm_content'  => $data['utm_content'] ?? null,
                'utm_term'     => $data['utm_term'] ?? null,
                'device'       => $data['device'] ?? null,
                'referrer'     => $data['referrer'] ?? null,
                'ip_hash'      => $data['ip_hash'] ?? null,
                'locale'       => $data['locale'] ?? 'fr',
                'submitted_at' => $data['submitted_at'] ?? now(),
            ]
        );

        return response()->json([
            'ok'      => true,
            'lead_id' => $lead->id,
        ], $lead->wasRecentlyCreated ? 201 : 200);
    }

    /** Permet au site client de vérifier que sa clé API est valide. */
    public function ping(Request $request): JsonResponse
    {
        $client = $request->attributes->get('client');

        return response()->json([
            'ok'     => true,
            'client' => $client->slug,
        ]);
    }

    /**
     * Traduit un numéro de capsule (1 à 72) en identifiant interne, en créant
     * la capsule au passage si c'est la première fois qu'on la voit. Les
     * capsules n'ont donc pas besoin d'être saisies d'avance : la première
     * visite issue d'une publication suffit à la faire apparaître.
     */
    private function resolveCapsuleId(Client $client, ?int $number): ?int
    {
        if (! $number) {
            return null;
        }

        return Capsule::firstOrCreate(
            ['client_id' => $client->id, 'number' => $number],
            ['title' => "Capsule {$number}"]
        )->id;
    }
}
