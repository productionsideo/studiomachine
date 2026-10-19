{{--
    Un indicateur : la tuile du total (et sa répartition par réseau), la courbe
    du total, puis une tuile par réseau avec sa mini-courbe.
    $titre, $series (réseau => [date => valeur]), $mode ('dernier' | 'somme'),
    $prec (réseau => total de la période précédente, mode 'somme'), $zero,
    $comptes, $collectes, $unite (ex. « vues »)
--}}
@php
    use App\Http\Controllers\ResumeController as R;
    use App\Services\Publication\Reseaux;

    $prec = $prec ?? [];
    $nb   = fn ($v) => $v === null ? '—' : ($v >= 1000000 ? number_format($v / 1000000, 1, ',', ' ') . ' M' : ($v >= 10000 ? number_format($v / 1000, 1, ',', ' ') . ' k' : number_format($v, 0, ',', ' ')));

    // Ce que montre la tuile d'un réseau : la dernière valeur (abonnés) ou la somme de la période.
    $resume = function (array $serie, ?int $avant) use ($mode) {
        $connus = array_values(array_filter($serie, fn ($v) => $v !== null));
        if (! $connus) {
            return [null, null];
        }
        return $mode === 'dernier'
            ? [end($connus), end($connus) - $connus[0]]
            : [array_sum($connus), $avant === null ? null : array_sum($connus) - $avant];
    };

    $parReseau = [];
    foreach ($comptes as $c) {
        $parReseau[$c->platform] = in_array($c->platform, $collectes, true)
            ? $resume($series[$c->platform] ?? [], $prec[$c->platform] ?? null)
            : null;
    }

    // Le total, jour par jour : la somme des réseaux qui ont une valeur ce jour-là.
    $total = []; $detail = [];
    foreach ($dates as $d) {
        $somme = null;
        foreach ($comptes as $c) {
            $v = $series[$c->platform][$d] ?? null;
            if ($v !== null) {
                $somme = ($somme ?? 0) + $v;
                $detail[$d][Reseaux::nom($c->platform)] = $v;
            }
        }
        $total[$d] = $somme;
    }
    $actifs      = array_filter($parReseau, fn ($r) => $r && $r[0] !== null);
    $valeurTotal = $actifs ? array_sum(array_column($actifs, 0)) : null;
    $ecarts      = array_filter(array_column($actifs, 1), fn ($e) => $e !== null);
    $ecartTotal  = $ecarts ? array_sum($ecarts) : null;
    $partTotal   = max(1, (int) $valeurTotal);

    $fleche = function (?int $ecart, ?int $valeur) use ($mode, $nb) {
        if ($ecart === null || $ecart === 0) {
            return $ecart === 0 ? '<span class="tendance">= stable</span>' : '';
        }
        $base = $mode === 'dernier' ? $valeur - $ecart : $valeur - $ecart;
        $pct  = $base > 0 ? ' (' . ($ecart > 0 ? '+' : '') . number_format($ecart / $base * 100, 0, ',', ' ') . ' %)' : '';
        return '<span class="tendance">' . ($ecart > 0 ? '↑ +' : '↓ −') . $nb(abs($ecart)) . $pct . '</span>';
    };
@endphp

<div class="bloc">
    <div class="bloc-tete">
        <div class="tuile-total">
            <div class="etiquette">{{ $titre }}</div>
            <div class="valeur">{{ $nb($valeurTotal) }}</div>
            {!! $fleche($ecartTotal, $valeurTotal) !!}
            @if($actifs)
                <div class="repartition" aria-hidden="true">
                    @foreach($actifs as $reseau => [$v])
                        <span style="flex: {{ max(0, $v) / $partTotal }}; background: {{ R::COULEURS[$reseau] }}"></span>
                    @endforeach
                </div>
            @endif
            <div class="sous-note">{{ $mode === 'dernier' ? 'aujourd’hui · écart sur la période' : 'sur la période · écart avec la précédente' }}</div>
        </div>

        <div class="bloc-courbe">
            @include('resume._courbe', ['serie' => $total, 'couleur' => R::COULEUR_TOTAL, 'zero' => $zero, 'nom' => $unite, 'detail' => $detail, 'barres' => $barres ?? false])
        </div>
    </div>

    <div class="tuiles-reseaux">
        @foreach($comptes as $c)
            @php $r = $parReseau[$c->platform]; @endphp
            <div class="tuile-reseau" style="--couleur: {{ R::COULEURS[$c->platform] ?? '#6b7280' }}">
                <div class="tuile-reseau-tete">
                    @include('publication._reseau', ['reseau' => $c->platform])
                    <span>{{ Reseaux::nom($c->platform) }}</span>
                </div>
                @if($r === null)
                    <div class="attente">Statistiques disponibles quand l’accès {{ Reseaux::nom($c->platform) }} sera accordé.</div>
                @else
                    <div class="valeur">{{ $nb($r[0]) }}</div>
                    {!! $fleche($r[1], $r[0]) !!}
                    @include('resume._courbe', ['serie' => $series[$c->platform] ?? [], 'couleur' => R::COULEURS[$c->platform], 'mini' => true, 'zero' => $zero, 'nom' => Reseaux::nom($c->platform), 'barres' => $barres ?? false])
                @endif
            </div>
        @endforeach
    </div>
</div>
