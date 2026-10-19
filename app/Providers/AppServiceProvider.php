<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Toute l'interface est en français : les dates aussi (« mardi 6 octobre »).
        \Illuminate\Support\Carbon::setLocale('fr');

        // Cloudflare garde les fichiers d'assets un an (« immutable ») : l'empreinte
        // du contenu dans l'adresse fait charger chaque nouvelle version aussitôt.
        // Partagée avec toutes les vues : la page de connexion se rend avant le gabarit.
        \Illuminate\Support\Facades\View::share('asset', function (string $fichier): string {
            $chemin = public_path($fichier);

            return asset($fichier) . (is_file($chemin) ? '?v=' . substr(md5_file($chemin), 0, 10) : '');
        });
    }
}
