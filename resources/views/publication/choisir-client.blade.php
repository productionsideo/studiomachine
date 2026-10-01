@extends('layouts.app')
@section('titre', 'Choisir un client')

@section('contenu')
<div class="tete">
    <div>
        <h1>Pour quel client ?</h1>
        <p class="sous">Médias, campagnes et comptes sociaux appartiennent à un client.</p>
    </div>
</div>

<div class="panneau">
    <div class="tableau-cadre">
        <table>
            <tbody>
            @forelse($clients as $c)
                <tr onclick="location='{{ route($suite, ['client' => $c->slug]) }}'" style="cursor:pointer">
                    <td><span class="pastille-couleur" style="background:{{ $c->accent_color }}"></span><strong>{{ $c->name }}</strong></td>
                    <td class="num" style="color:var(--encre-3)">Choisir →</td>
                </tr>
            @empty
                <tr><td class="vide">Aucun client actif.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
