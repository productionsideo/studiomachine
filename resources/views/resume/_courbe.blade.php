{{--
    Une courbe dans le temps, une seule série (le total, ou un réseau dans sa tuile).
    $serie   : ['2026-10-01' => 123|null, …]
    $couleur : couleur de la série
    $mini    : true = sans axes (mini-courbe d'une tuile)
    $zero    : true = l'axe part de zéro (comptes du jour) ; false = de la plus petite valeur (abonnés)
    $nom     : ce que la série mesure (infobulle)
    $detail  : facultatif, ['2026-10-01' => ['Facebook' => 12, …]] pour l'infobulle du total
    $barres  : true = colonnes (des comptes entiers par jour) plutôt qu'une ligne
--}}
@php
    $mini   = $mini ?? false;
    $zero   = $zero ?? true;
    $detail = $detail ?? [];
    $barres = $barres ?? false;
    $h      = $mini ? 44 : 190;
    $dates  = array_keys($serie);
    $vals   = array_values($serie);
    $n      = count($vals);
    $connus = array_filter($vals, fn ($v) => $v !== null);

    $max = $connus ? max($connus) : 0;
    $min = $zero || ! $connus ? 0 : min($connus);

    // Graduations « rondes » (1, 2, 2,5, 5 × 10ⁿ), trois ou quatre au plus.
    $etendue = max(1, $max - $min);
    $brut    = $etendue / 3;
    $p10     = 10 ** floor(log10($brut));
    $pas     = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $p10)->first(fn ($s) => $s >= $brut);
    $bas     = $zero ? 0 : floor($min / $pas) * $pas;
    $haut    = max($bas + $pas, ceil($max / $pas) * $pas);
    $ticks   = [];
    for ($t = $bas; $t <= $haut + $pas / 2; $t += $pas) { $ticks[] = $t; }

    // En colonnes, chaque jour occupe une case et sa colonne est centrée dedans.
    $x = $barres
        ? fn ($i) => ($i + 0.5) / max(1, $n) * 1000
        : fn ($i) => $n > 1 ? $i / ($n - 1) * 1000 : 500;
    $y = fn ($v) => $h - ($v - $bas) / max(1, $haut - $bas) * $h;

    // Tracé, interrompu là où la valeur manque (le réseau ne la donne pas ce jour-là).
    $ligne = ''; $aire = ''; $segment = [];
    $fermer = function () use (&$segment, &$aire, $h) {
        if (count($segment) > 1) {
            $aire .= 'M' . $segment[0][0] . " {$h} L" . implode(' L', array_map(fn ($p) => "{$p[0]} {$p[1]}", $segment)) . ' L' . end($segment)[0] . " {$h} Z ";
        }
        $segment = [];
    };
    foreach ($vals as $i => $v) {
        if ($v === null) { $fermer(); continue; }
        $pt = [round($x($i), 1), round($y($v), 1)];
        $ligne .= ($segment ? ' L' : ' M') . "{$pt[0]} {$pt[1]}";
        $segment[] = $pt;
    }
    $fermer();

    $dernier = null;
    foreach (array_reverse($vals, true) as $i => $v) { if ($v !== null) { $dernier = [$i, $v]; break; } }

    $compact = fn ($v) => $v >= 1000000 ? number_format($v / 1000000, 1, ',', ' ') . ' M' : ($v >= 10000 ? number_format($v / 1000, 1, ',', ' ') . ' k' : number_format($v, 0, ',', ' '));
    $etiquettesX = $n > 1 ? array_unique([0, (int) round(($n - 1) / 4), (int) round(($n - 1) / 2), (int) round(3 * ($n - 1) / 4), $n - 1]) : [0];
@endphp

<div @class(['graphe', 'mini' => $mini]) style="--h: {{ $h }}px; --couleur: {{ $couleur }}"
     data-dates='@json($dates)' data-valeurs='@json($vals)' data-nom="{{ $nom ?? '' }}" @if($detail) data-detail='@json($detail)' @endif @if($barres) data-barres="1" @endif>
    @if(! $connus)
        <div class="graphe-vide">{{ $mini ? '—' : 'Pas encore de données pour cette période.' }}</div>
    @else
        @unless($mini)
            <div class="graphe-y">
                @foreach(array_reverse($ticks) as $t)
                    <span style="top: {{ $y($t) / $h * 100 }}%">{{ $compact($t) }}</span>
                @endforeach
            </div>
        @endunless
        <div class="graphe-zone">
            <svg viewBox="0 0 1000 {{ $h }}" preserveAspectRatio="none" aria-hidden="true">
                @unless($mini)
                    @foreach($ticks as $t)
                        <line x1="0" x2="1000" y1="{{ $y($t) }}" y2="{{ $y($t) }}" class="grille" vector-effect="non-scaling-stroke"/>
                    @endforeach
                @endunless
                @unless($barres)
                    <path d="{{ $aire }}" class="aire"/>
                    <path d="{{ $ligne }}" class="trait" vector-effect="non-scaling-stroke"/>
                @endunless
            </svg>
            @if($barres)
                <div class="colonnes">
                    @foreach($vals as $v)
                        <span><i style="height: {{ $v ? max(2, ($v - $bas) / max(1, $haut - $bas) * 100) : 0 }}%"></i></span>
                    @endforeach
                </div>
            @elseif($dernier)
                <span class="point-fin" style="left: {{ $x($dernier[0]) / 10 }}%; top: {{ $y($dernier[1]) / $h * 100 }}%"></span>
            @endif
            <span class="curseur" hidden></span>
            <span class="infobulle" hidden></span>
        </div>
        @unless($mini)
            <div class="graphe-x">
                @foreach($etiquettesX as $i)
                    <span style="left: {{ $x($i) / 10 }}%">{{ \Illuminate\Support\Carbon::parse($dates[$i])->translatedFormat('j M') }}</span>
                @endforeach
            </div>
        @endunless
    @endif
</div>
