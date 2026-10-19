@extends('layouts.app')
@section('titre', 'Résumé')

@section('contenu')
<div class="tete">
    <div>
        <h1>Résumé</h1>
        <p class="sous">Choisissez un client pour voir ses statistiques de réseaux sociaux.</p>
    </div>
</div>

<div class="panneau">
    <div class="tableau-cadre">
        <table>
            <tbody>
            @foreach($clients as $c)
                <tr onclick="location='{{ route('resume.index', ['client' => $c->slug]) }}'" style="cursor:pointer">
                    <td><strong>{{ $c->name }}</strong></td>
                    <td style="text-align:right"><a href="{{ route('resume.index', ['client' => $c->slug]) }}"><u>Ouvrir</u></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
