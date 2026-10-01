<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Le calendrier éditorial : un mois, du lundi au dimanche, dans le fuseau
 * de l'équipe. Un admin sans client choisi voit tous les clients à la fois —
 * c'est la vue qui dit si une journée est surchargée.
 */
class CalendrierController extends Controller
{
    use ResolvesClient;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);
        $fuseau = config('publication.fuseau');

        try {
            $mois = Carbon::createFromFormat('Y-m-d', $request->query('mois', '') . '-01', $fuseau)->startOfMonth();
        } catch (\Throwable) {
            $mois = now($fuseau)->startOfMonth();
        }

        $debut = $mois->copy()->startOfWeek(Carbon::MONDAY);
        $fin   = $mois->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $posts = Post::query()
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('scheduled_at', [$debut->copy()->utc(), $fin->copy()->utc()])
            ->with('targets', 'campaign', 'client', 'media')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn ($p) => $p->heureLocale()->toDateString());

        $brouillons = Post::query()
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->where('status', 'brouillon')
            ->whereNull('scheduled_at')
            ->with('client', 'targets')
            ->latest()
            ->limit(20)
            ->get();

        $jours = [];
        for ($j = $debut->copy(); $j->lte($fin); $j->addDay()) {
            $jours[] = $j->copy();
        }

        return view('publication.calendrier', [
            'client'     => $client,
            'clients'    => $request->user()->isAdmin() ? Client::where('active', true)->orderBy('name')->get() : collect(),
            'mois'       => $mois,
            'jours'      => $jours,
            'posts'      => $posts,
            'brouillons' => $brouillons,
            'aujourdhui' => now($fuseau)->toDateString(),
        ]);
    }
}
