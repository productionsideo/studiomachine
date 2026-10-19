<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Le calendrier éditorial, dans le fuseau de l'équipe : une semaine heure par
 * heure (par défaut, comme Metricool) ou un mois. Un admin sans client choisi
 * voit tous les clients à la fois — c'est la vue qui dit si une journée est
 * surchargée.
 *
 * L'équipe y déplace les publications par glisser-déposer (deplacer()) ; le
 * client, lui, consulte.
 */
class CalendrierController extends Controller
{
    use ResolvesClient;

    /** Hauteur d'une heure dans la vue semaine, en pixels (repris par la vue et son script). */
    public const HEURE_PX = 56;

    /** Durée « visuelle » d'une carte : deux cartes plus proches se partagent la largeur. */
    private const CARTE_MINUTES = 80;

    public function index(Request $request)
    {
        $client = $this->resolveClient($request);
        $fuseau = config('publication.fuseau');
        $vue    = $request->query('vue') === 'mois' ? 'mois' : 'semaine';

        if ($vue === 'semaine') {
            try {
                $repere = Carbon::createFromFormat('Y-m-d', (string) $request->query('semaine'), $fuseau);
            } catch (\Throwable) {
                $repere = now($fuseau);
            }

            $debut = $repere->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $fin   = $debut->copy()->addDays(6)->endOfDay();
            $mois  = $debut->copy()->startOfMonth();
        } else {
            try {
                $mois = Carbon::createFromFormat('Y-m-d', $request->query('mois', '') . '-01', $fuseau)->startOfMonth();
            } catch (\Throwable) {
                $mois = now($fuseau)->startOfMonth();
            }

            $debut = $mois->copy()->startOfWeek(Carbon::MONDAY);
            $fin   = $mois->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        }

        $posts = Post::query()
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('scheduled_at', [$debut->copy()->utc(), $fin->copy()->utc()])
            ->with('targets', 'campaign', 'client', 'media', 'fichiersAttendus')
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
            'vue'        => $vue,
            'mois'       => $mois,
            'debut'      => $debut,
            'jours'      => $jours,
            'posts'      => $posts,
            'placements' => $vue === 'semaine' ? $posts->map(fn ($liste) => $this->placer($liste)) : collect(),
            'brouillons' => $brouillons,
            'aujourdhui' => now($fuseau)->toDateString(),
            'heurePx'    => self::HEURE_PX,
        ]);
    }

    /**
     * Déplace une publication à un autre moment (glisser-déposer). Seulement
     * tant que rien n'est parti : la même règle que l'éditeur.
     */
    public function deplacer(Request $request, Post $post)
    {
        $d = $request->validate(['quand' => ['required', 'date_format:Y-m-d H:i']]);

        $quand = Carbon::createFromFormat('Y-m-d H:i', $d['quand'], config('publication.fuseau'))->utc();

        if ($post->status !== 'brouillon' && $quand->lt(now()->addMinute())) {
            return response()->json(['message' => 'Ce moment est déjà passé : choisissez une heure à venir.'], 422);
        }

        // Verrou : l'envoi (chaque minute) réserve les cibles par une mise à
        // jour conditionnelle. En relisant sous verrou, on ne déplace jamais
        // une publication qu'il vient de prendre.
        $deplacee = DB::transaction(function () use ($post, $quand) {
            $frais = Post::whereKey($post->id)->lockForUpdate()->first();
            $frais->load('targets');

            if (! $frais->estModifiable()) {
                return null;
            }

            $frais->update(['scheduled_at' => $quand]);

            return $frais;
        });

        if (! $deplacee) {
            return response()->json(['message' => 'Cette publication est déjà partie (ou en cours) : elle ne peut plus être déplacée.'], 409);
        }

        return response()->json([
            'message' => 'Déplacée au ' . $deplacee->heureLocale()->translatedFormat('l j F à H \h i') . '.',
            'heure'   => $deplacee->heureLocale()->format('H:i'),
            'jour'    => $deplacee->heureLocale()->toDateString(),
        ]);
    }

    /**
     * Position de chaque carte dans sa journée : haut (minutes depuis minuit)
     * et colonne, quand plusieurs publications se chevauchent. Les cartes qui
     * se touchent forment une grappe dont elles se partagent la largeur.
     *
     * @return array<int, array{haut:int, colonne:int, colonnes:int}>  par id de publication
     */
    private function placer($liste): array
    {
        $places    = [];
        $grappe    = [];
        $finGrappe = -1;

        $fermer = function () use (&$grappe, &$places) {
            $n = max(array_column($grappe, 'colonne')) + 1;
            foreach ($grappe as $id => $p) {
                $places[$id] = $p + ['colonnes' => $n];
            }
            $grappe = [];
        };

        foreach ($liste as $post) {
            $h      = $post->heureLocale();
            $minute = $h->hour * 60 + $h->minute;

            if ($grappe && $minute >= $finGrappe) {
                $fermer();
            }

            // Première colonne libre à cette minute.
            $occupees = [];
            foreach ($grappe as $p) {
                if ($p['haut'] + self::CARTE_MINUTES > $minute) {
                    $occupees[] = $p['colonne'];
                }
            }
            $colonne = 0;
            while (in_array($colonne, $occupees, true)) {
                $colonne++;
            }

            $grappe[$post->id] = ['haut' => $minute, 'colonne' => $colonne];
            $finGrappe = max($finGrappe, $minute + self::CARTE_MINUTES);
        }

        if ($grappe) {
            $fermer();
        }

        return $places;
    }
}
