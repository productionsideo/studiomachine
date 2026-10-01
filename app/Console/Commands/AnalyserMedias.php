<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\Mediatheque;
use Illuminate\Console\Command;

/**
 * Relit dimensions et durée des médias dont l'analyse a échoué à l'import.
 *
 * Ces valeurs servent aux vérifications avant publication (durée d'un Reel,
 * format vertical d'un Short) : un média sans elles passerait sans contrôle.
 */
class AnalyserMedias extends Command
{
    protected $signature = 'medias:analyser {--tous : Réanalyser aussi les médias déjà renseignés}';

    protected $description = 'Complète largeur, hauteur et durée des médias (ffprobe)';

    public function handle(Mediatheque $mediatheque): int
    {
        $medias = MediaAsset::query()
            ->when(! $this->option('tous'), fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('width')
                ->orWhere(fn ($q) => $q->where('kind', 'video')->whereNull('duration_seconds'))))
            ->get();

        foreach ($medias as $media) {
            // ffprobe lit aussi une adresse : un média sur R2 s'analyse sans le copier.
            $source = $media->surR2() ? $media->url() : $media->chemin();
            $infos  = $media->disponible() ? $mediatheque->sonder($source) : [];

            if (! $infos) {
                $this->error("  ✗ #{$media->id} {$media->original_name} : analyse impossible");
                continue;
            }

            $media->update([
                'width'            => $infos['width'] ?? null,
                'height'           => $infos['height'] ?? null,
                'duration_seconds' => $media->isVideo() ? ($infos['duration'] ?? null) : null,
            ]);

            $this->line("  ✓ #{$media->id} {$media->original_name} : {$media->width}×{$media->height}"
                . ($media->isVideo() ? ", {$media->duration_seconds} s" : ''));
        }

        $this->info($medias->count() . ' média(s) traité(s).');

        return self::SUCCESS;
    }
}
