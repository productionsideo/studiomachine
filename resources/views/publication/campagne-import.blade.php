@extends('layouts.app')
@section('titre', 'Importer — ' . $campaign->name)

@php use App\Services\Publication\Reseaux; @endphp

@section('contenu')
<div class="tete">
    <div>
        <h1>Importer des publications</h1>
        <p class="sous">{{ $campaign->client->name }} · campagne <strong>{{ $campaign->name }}</strong> — tout arrive en brouillon.</p>
    </div>
    <div class="outils">
        <a class="bouton-fin" href="{{ route('campaigns.gabarit') }}">Télécharger le gabarit CSV</a>
        <a class="bouton-fin" href="{{ route('campaigns.show', $campaign) }}">Retour à la campagne</a>
    </div>
</div>

@if(! $analyse)
    <div class="grille grille-2">
        <div class="panneau">
            <h2>1. Déposer le fichier</h2>
            <form method="post" action="{{ route('campaigns.import.apercu', $campaign) }}" enctype="multipart/form-data" class="panneau-corps">
                @csrf
                <div class="champ">
                    <label for="fichier">Fichier CSV</label>
                    <input type="file" id="fichier" name="fichier" accept=".csv,text/csv" required>
                    @error('fichier') <div class="erreur" style="display:block">{{ $message }}</div> @enderror
                </div>
                <p style="font-size:13px;color:var(--encre-3);margin-bottom:12px">
                    Rien n’est créé à cette étape : vous verrez d’abord ce qui sera importé.
                    Excel ou Numbers : <em>Fichier → Exporter → CSV</em> (séparateur virgule ou point-virgule, les deux passent).
                </p>
                <button type="submit" class="bouton bouton-accent" style="width:auto">Voir l’aperçu</button>
            </form>
        </div>

        <div class="panneau">
            <h2>Comment ça marche</h2>
            <div class="panneau-corps" style="font-size:14px">
                <p><strong>Une ligne par publication et par réseau.</strong> Les lignes qui ont la même date, la même heure,
                    le même titre et les mêmes fichiers deviennent une seule publication sur plusieurs réseaux. Une heure
                    différente pour un réseau donne une publication à part.</p>
                <p style="margin-top:8px"><strong>Les fichiers se rattachent seuls.</strong> Écrivez le nom du fichier
                    (<code>regle01.mp4</code>) dans les colonnes <em>video</em>, <em>images</em> ou <em>miniature</em>. S’il est
                    déjà dans la Médiathèque, il est pris tout de suite ; sinon, la publication l’attend et il s’y attache
                    dès que vous le déposez.</p>
            </div>
        </div>
    </div>

    <div class="panneau" style="margin-top:18px">
        <h2>Les colonnes</h2>
        <div class="tableau-cadre">
            <table>
                <thead><tr><th>Colonne</th><th>Contenu</th></tr></thead>
                <tbody>
                <tr><td><code>date</code> · <code>heure</code></td><td>Obligatoires. <code>2026-11-03</code> (ou <code>03/11/2026</code>) et <code>12:00</code> (ou <code>12h00</code>), heure de Montréal.</td></tr>
                <tr><td><code>reseau</code></td><td>Obligatoire. facebook, instagram, youtube, tiktok, linkedin (Page) ou linkedin profil.</td></tr>
                <tr><td><code>format</code></td><td>Vide = format principal du réseau.
                    @foreach(Reseaux::CATALOGUE as $cle => $r) {{ $r['nom'] }} : {{ implode(', ', array_keys($r['formats'])) }}@if(! $loop->last) · @endif @endforeach</td></tr>
                <tr><td><code>titre</code></td><td>Titre de la publication ; obligatoire pour YouTube (100 caractères au plus).</td></tr>
                <tr><td><code>texte</code></td><td>Le texte publié sur ce réseau.</td></tr>
                <tr><td><code>hashtags</code></td><td>Séparés par des espaces : <code>#blackjack #bj21rules</code> (le # est ajouté s’il manque). Ajoutés à la fin du texte.</td></tr>
                <tr><td><code>mentions</code></td><td><code>@compte</code> séparés par des espaces, ajoutés au texte. Liens cliquables sur Instagram, TikTok et YouTube ; simple texte sur Facebook et LinkedIn (leurs API ne permettent pas d’identifier par @pseudo).</td></tr>
                <tr><td><code>collaborateurs</code></td><td>Instagram seulement : jusqu’à 3 comptes invités comme collaborateurs (images, carrousels, Reels).</td></tr>
                <tr><td><code>video</code></td><td>Nom du fichier vidéo, ex. <code>regle01.mp4</code>.</td></tr>
                <tr><td><code>images</code></td><td>Une ou plusieurs images séparées par <code>|</code> : <code>a.jpg|b.jpg</code> (carrousel).</td></tr>
                <tr><td><code>miniature</code></td><td>Image de couverture d’une vidéo : YouTube, Reels Instagram et Facebook, LinkedIn. TikTok n’en accepte pas.</td></tr>
                <tr><td><code>lien</code></td><td>Lien suivi (les codes UTM de la campagne sont ajoutés seuls).</td></tr>
                <tr><td><code>visibilite</code></td><td>YouTube : public, non répertoriée, privée. TikTok : public, amis, abonnés, privé (obligatoire chez TikTok).</td></tr>
                <tr><td><code>ia</code></td><td><code>oui</code> si le contenu est généré ou modifié par IA (divulgation YouTube et TikTok).</td></tr>
                <tr><td><code>groupe</code></td><td>Facultatif : un même mot sur plusieurs lignes force leur regroupement en une publication.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
@else
    @if($analyse['erreurs'])
        <div class="avis avis-garde">
            <strong>{{ count($analyse['erreurs']) }} problème{{ count($analyse['erreurs']) > 1 ? 's' : '' }} à corriger dans le fichier</strong> — rien n’a été importé.
            <ul style="margin:8px 0 0 18px">
                @foreach($analyse['erreurs'] as $e) <li>{{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @if($analyse['avertissements'])
        <div class="avis avis-info">
            <strong>À savoir</strong>
            <ul style="margin:8px 0 0 18px">
                @foreach($analyse['avertissements'] as $a) <li>{{ $a }}</li> @endforeach
            </ul>
        </div>
    @endif

    @if($analyse['publications'] && ! $analyse['erreurs'])
        <div class="panneau">
            <h2>{{ count($analyse['publications']) }} publication{{ count($analyse['publications']) > 1 ? 's' : '' }} à créer <span class="aide">en brouillon</span></h2>
            <div class="tableau-cadre">
                <table>
                    <thead><tr><th>Quand</th><th>Titre</th><th>Réseaux</th><th>Fichiers</th><th>Lignes</th></tr></thead>
                    <tbody>
                    @foreach($analyse['publications'] as $p)
                        <tr>
                            <td style="white-space:nowrap">{{ $p['quand']->translatedFormat('D j M · H:i') }}</td>
                            <td>{{ $p['titre'] ?: \Illuminate\Support\Str::limit($p['texte'], 60) ?: '—' }}</td>
                            <td style="white-space:nowrap">
                                @foreach($p['cibles'] as $reseau => $c)
                                    @include('publication._reseau', ['reseau' => $reseau, 'titre' => Reseaux::nom($reseau) . ' — ' . (Reseaux::CATALOGUE[$reseau]['formats'][$c['format']] ?? $c['format'])])
                                @endforeach
                            </td>
                            <td style="font-size:13px">
                                @forelse($p['fichiers'] as $nom => $present)
                                    <div>{{ $present ? '✓' : '⏳' }} {{ $nom }}</div>
                                @empty
                                    <span style="color:var(--encre-3)">texte seul</span>
                                @endforelse
                            </td>
                            <td class="num" style="color:var(--encre-3)">{{ implode(', ', $p['lignes']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <form method="post" action="{{ route('campaigns.import.confirmer', $campaign) }}" class="barre-actions">
                @csrf
                <input type="hidden" name="jeton" value="{{ $jeton }}">
                <span style="font-size:13px;color:var(--encre-3)">✓ déjà dans la Médiathèque · ⏳ s’attachera au dépôt</span>
                <span class="espace"></span>
                <a class="bouton-fin" href="{{ route('campaigns.import', $campaign) }}">Changer de fichier</a>
                <button type="submit" class="bouton bouton-accent" style="width:auto">Importer en brouillon</button>
            </form>
        </div>
    @else
        <a class="bouton-fin" href="{{ route('campaigns.import', $campaign) }}">Déposer un fichier corrigé</a>
    @endif
@endif
@endsection
