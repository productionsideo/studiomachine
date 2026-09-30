<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $leads = $this->query($request)
            ->with(['capsule', 'client'])
            ->latest('submitted_at')
            ->paginate(30)
            ->withQueryString();

        return view('dashboard.leads', [
            'leads'   => $leads,
            'client'  => $this->resolveClient($request),
            'clients' => $request->user()->isAdmin() ? Client::orderBy('name')->get() : collect(),
            'statuts' => Lead::STATUSES,
            'projets' => Lead::PROJECTS,
        ]);
    }

    public function show(Request $request, Lead $lead)
    {
        $this->authorizeClient($request, $lead->client_id);

        return view('dashboard.lead-show', [
            'lead'    => $lead->load(['capsule', 'client']),
            'statuts' => Lead::STATUSES,
        ]);
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorizeClient($request, $lead->client_id);

        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'notes'  => ['nullable', 'string', 'max:5000'],
        ]);

        $lead->update($data);

        return back()->with('ok', 'Demande mise à jour.');
    }

    /**
     * Export CSV, en flux plutôt qu'en mémoire : le fichier peut grossir
     * sans jamais dépasser la limite mémoire de PHP.
     * BOM UTF-8 en tête pour qu'Excel affiche correctement les accents.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request)->with(['capsule', 'client']);
        $nom   = 'demandes-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Date', 'Client', 'Nom', 'Courriel', 'Téléphone',
                'Projet', 'Type', 'Chambres', 'Budget', 'Financement', 'Échéancier',
                'Message', 'Capsule', 'Réseau', 'Source', 'Campagne', 'Appareil', 'Statut',
            ], ';');

            $query->chunk(500, function ($leads) use ($out) {
                foreach ($leads as $l) {
                    fputcsv($out, [
                        optional($l->submitted_at)->format('Y-m-d H:i'),
                        $l->client->name ?? '',
                        $l->name,
                        $l->email,
                        $l->phone,
                        $l->project_label,
                        $l->home_type_label,
                        $l->bedrooms_label,
                        $l->budget_label,
                        $l->financing_label,
                        $l->timeline_label,
                        $l->message,
                        $l->capsule?->number,
                        $l->platform,
                        $l->utm_source,
                        $l->utm_campaign,
                        $l->device,
                        $l->status,
                    ], ';');
                }
            });

            fclose($out);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * La requête de base, déjà cloisonnée par client.
     * Un utilisateur client ne peut jamais en sortir : son client_id est
     * imposé par son compte, pas par l'URL.
     */
    private function query(Request $request)
    {
        $user  = $request->user();
        $query = Lead::query();

        if ($user->isAdmin()) {
            if ($client = $this->resolveClient($request)) {
                $query->where('client_id', $client->id);
            }
        } else {
            $query->where('client_id', $user->client_id);
        }

        if ($statut = $request->query('statut')) {
            if (in_array($statut, Lead::STATUSES, true)) {
                $query->where('status', $statut);
            }
        }

        if ($capsule = $request->query('capsule')) {
            $query->whereHas('capsule', fn ($q) => $q->where('number', (int) $capsule));
        }

        // Filtrer par développement : c'est la question qu'un représentant se
        // pose en premier — « qui veut bâtir à Boisé Natura ? ».
        if ($projet = $request->query('projet')) {
            if (array_key_exists($projet, Lead::PROJECTS)) {
                $query->where('project', $projet);
            }
        }

        if ($recherche = $request->query('q')) {
            $query->where(function ($q) use ($recherche) {
                $q->where('name', 'like', "%{$recherche}%")
                  ->orWhere('email', 'like', "%{$recherche}%")
                  ->orWhere('phone', 'like', "%{$recherche}%");
            });
        }

        return $query;
    }
}
