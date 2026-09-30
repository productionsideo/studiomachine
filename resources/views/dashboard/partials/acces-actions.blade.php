{{--
    Les trois actions possibles sur un accès.

    Aucune n'est offerte sur soi-même : se suspendre ou se supprimer depuis
    l'interface fermerait la porte de l'intérieur, et il faudrait aller rouvrir
    en base. Le contrôleur refuse déjà ces deux gestes — on ne montre pas
    non plus les boutons, pour ne pas promettre ce qu'on refusera.
--}}
@if($u->id !== auth()->id())
    <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">

        <details style="position:relative">
            <summary class="bouton-fin" style="cursor:pointer;list-style:none">Mot de passe</summary>
            <div style="position:absolute;right:0;top:calc(100% + 6px);z-index:20;
                        background:var(--surface);border:1px solid var(--trait);
                        border-radius:8px;padding:14px;width:270px;
                        box-shadow:0 10px 30px rgba(0,0,0,.10);text-align:left">
                <form method="post" action="{{ route('users.password', $u) }}">
                    @csrf
                    @method('patch')
                    <div class="champ" style="margin-bottom:10px">
                        <label style="font-size:12px">Nouveau mot de passe</label>
                        <input type="password" name="password" required minlength="12" autocomplete="new-password">
                    </div>
                    <div class="champ" style="margin-bottom:12px">
                        <label style="font-size:12px">Répéter</label>
                        <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
                    </div>
                    <button type="submit" class="bouton bouton-accent" style="width:100%">Remplacer</button>
                </form>
            </div>
        </details>

        <form method="post" action="{{ route('users.toggle', $u) }}">
            @csrf
            @method('patch')
            <button type="submit" class="bouton-fin">
                {{ $u->active ? 'Suspendre' : 'Réactiver' }}
            </button>
        </form>

        <form method="post" action="{{ route('users.destroy', $u) }}"
              onsubmit="return confirm('Supprimer définitivement l’accès de {{ $u->name }} ?')">
            @csrf
            @method('delete')
            <button type="submit" class="bouton-fin" style="color:var(--rouge)">Supprimer</button>
        </form>

    </div>
@else
    <span style="color:var(--encre-3);font-size:12px">—</span>
@endif
