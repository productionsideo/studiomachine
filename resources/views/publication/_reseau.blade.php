{{-- Pastille d'un réseau : carré à sa couleur, avec une lettre. $reseau = clé du catalogue ; $titre facultatif. --}}
@php
    $lettre = ['facebook' => 'f', 'instagram' => 'IG', 'youtube' => '▶', 'tiktok' => '♪', 'linkedin' => 'in', 'linkedin_profil' => 'in'][$reseau] ?? '•';
@endphp
<span class="badge-reseau badge-{{ $reseau }}" title="{{ $titre ?? \App\Services\Publication\Reseaux::nom($reseau) }}">{{ $lettre }}</span>
