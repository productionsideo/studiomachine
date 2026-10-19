<?php

namespace App\Services\Publication;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\FichierAttendu;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import d'une campagne depuis un fichier CSV : une ligne par publication ET
 * par réseau, pour que chaque réseau ait sa propre programmation.
 *
 * Les lignes qui partagent le même moment, le même titre et les mêmes
 * fichiers forment une seule publication à plusieurs réseaux (le modèle de
 * /gestion) ; une heure différente donne une publication distincte. La
 * colonne « groupe », facultative, force le regroupement.
 *
 * Tout arrive en BROUILLON : rien ne part sans relecture. Les fichiers nommés
 * (video, images, miniature) sont pris dans la médiathèque s'ils y sont déjà,
 * sinon attendus : ils se rattacheront seuls au dépôt (FichiersAttendus).
 *
 * analyser() ne touche à rien : c'est l'aperçu montré avant confirmation.
 */
class ImportCsv
{
    public const COLONNES = [
        'date', 'heure', 'reseau', 'format', 'titre', 'texte', 'hashtags', 'mentions',
        'collaborateurs', 'video', 'images', 'miniature', 'lien', 'visibilite', 'ia', 'groupe',
    ];

    private const RESEAUX = [
        'facebook' => 'facebook', 'fb' => 'facebook',
        'instagram' => 'instagram', 'ig' => 'instagram',
        'youtube' => 'youtube', 'yt' => 'youtube',
        'tiktok' => 'tiktok',
        'linkedin' => 'linkedin', 'linkedin page' => 'linkedin', 'linkedin_page' => 'linkedin', 'linkedin-page' => 'linkedin',
        'linkedin profil' => 'linkedin_profil', 'linkedin_profil' => 'linkedin_profil', 'linkedin-profil' => 'linkedin_profil',
    ];

    private const VISIBILITE_YOUTUBE = [
        'public' => 'public', 'publique' => 'public',
        'non répertoriée' => 'unlisted', 'non repertoriee' => 'unlisted', 'unlisted' => 'unlisted',
        'privée' => 'private', 'privee' => 'private', 'private' => 'private',
    ];

    private const VISIBILITE_TIKTOK = [
        'public' => 'PUBLIC_TO_EVERYONE', 'tout le monde' => 'PUBLIC_TO_EVERYONE',
        'amis' => 'MUTUAL_FOLLOW_FRIENDS',
        'abonnés' => 'FOLLOWER_OF_CREATOR', 'abonnes' => 'FOLLOWER_OF_CREATOR',
        'privé' => 'SELF_ONLY', 'prive' => 'SELF_ONLY', 'moi' => 'SELF_ONLY',
    ];

    public function __construct(private FichiersAttendus $attendus) {}

    /** Le gabarit à télécharger : l'en-tête et trois exemples commentés par leur contenu. */
    public function gabarit(): string
    {
        $lignes = [
            self::COLONNES,
            ['2026-11-03', '12:00', 'facebook', 'reel', 'Règle 1 — le hasard', 'Le hasard est incontrôlable. Ta stratégie, elle, ne l’est pas.', '#blackjack #bj21rules', '', '', 'regle01.mp4', '', 'regle01-couverture.jpg', 'https://bj21rules.com', '', 'oui', ''],
            ['2026-11-03', '12:00', 'instagram', 'reel', 'Règle 1 — le hasard', 'Le hasard est incontrôlable. Ta stratégie, elle, ne l’est pas.', '#blackjack #bj21rules #casino', '@partenaire', 'partenaire', 'regle01.mp4', '', 'regle01-couverture.jpg', '', '', 'oui', ''],
            ['2026-11-03', '17:30', 'youtube', 'short', 'Règle 1 — le hasard au blackjack', 'La règle 1 en 30 secondes.', '#blackjack #shorts', '@bj21rules', '', 'regle01.mp4', '', 'regle01-couverture.jpg', 'https://bj21rules.com', 'public', 'oui', ''],
            ['2026-11-04', '09:00', 'linkedin', 'post', 'Règle 2', 'Une mauvaise décision coûte plus qu’une main perdue.', '#strategie', '', '', '', 'regle02-a.jpg|regle02-b.jpg', '', 'https://bj21rules.com', '', '', ''],
        ];

        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\u{FEFF}");      // BOM : Excel lit alors les accents correctement
        foreach ($lignes as $l) {
            fputcsv($flux, $l, ',', '"', '');
        }
        rewind($flux);

        return stream_get_contents($flux);
    }

    /**
     * Lit le CSV et dit ce qui serait créé, sans rien écrire.
     *
     * @return array{publications: array, erreurs: array<string>, avertissements: array<string>}
     */
    public function analyser(Client $client, string $contenu): array
    {
        $erreurs = [];
        $avert   = [];

        $lignes = $this->lire($contenu, $erreurs);
        if ($erreurs) {
            return ['publications' => [], 'erreurs' => $erreurs, 'avertissements' => []];
        }

        $comptes = $client->integrations()->where('active', true)->pluck('platform')->all();
        $fuseau  = config('publication.fuseau');
        $groupes = [];

        foreach ($lignes as $n => $l) {
            $ici = "Ligne {$n}";

            $reseau = self::RESEAUX[mb_strtolower(trim($l['reseau']))] ?? null;
            if (! $reseau) {
                $erreurs[] = "{$ici} : réseau inconnu « {$l['reseau']} » (facebook, instagram, youtube, tiktok, linkedin, linkedin profil).";
                continue;
            }

            $quand = $this->moment($l['date'], $l['heure'], $fuseau);
            if (! $quand) {
                $erreurs[] = "{$ici} : date « {$l['date']} » ou heure « {$l['heure']} » illisible (ex. 2026-11-03 et 12:00).";
                continue;
            }

            $format = $this->format($reseau, $l['format']);
            if (! $format) {
                $erreurs[] = "{$ici} : format « {$l['format']} » inconnu pour " . Reseaux::nom($reseau) . ' (' . implode(', ', array_keys(Reseaux::CATALOGUE[$reseau]['formats'])) . ').';
                continue;
            }

            $video  = trim($l['video']);
            $images = array_values(array_filter(array_map('trim', explode('|', $l['images']))));
            if ($video !== '' && $images) {
                $erreurs[] = "{$ici} : une publication contient soit une vidéo, soit des images — pas les deux.";
                continue;
            }

            $cle = trim($l['groupe']) !== ''
                ? 'g:' . mb_strtolower(trim($l['groupe']))
                : implode('|', [$quand->toIso8601String(), mb_strtolower(trim($l['titre'])), mb_strtolower($video), mb_strtolower(implode('|', $images))]);

            $groupes[$cle] ??= [
                'lignes'    => [],
                'quand'     => $quand,
                'titre'     => trim($l['titre']),
                'texte'     => trim($l['texte']),
                'lien'      => trim($l['lien']),
                'video'     => $video,
                'images'    => $images,
                'miniature' => trim($l['miniature']),
                'cibles'    => [],
            ];
            $g = &$groupes[$cle];
            $g['lignes'][] = $n;

            if (isset($g['cibles'][$reseau])) {
                $erreurs[] = "{$ici} : " . Reseaux::nom($reseau) . ' apparaît deux fois pour la même publication (lignes ' . implode(', ', $g['lignes']) . ').';
                unset($g);
                continue;
            }

            if ($g['video'] !== $video || $g['images'] !== $images || ! $g['quand']->eq($quand)) {
                $erreurs[] = "{$ici} : même groupe que la ligne {$g['lignes'][0]}, mais pas la même date, heure ou les mêmes fichiers.";
                unset($g);
                continue;
            }

            [$cible, $avertCible] = $this->cible($reseau, $format, $l, $ici);
            $g['cibles'][$reseau] = $cible;
            array_push($avert, ...$avertCible);

            if (! in_array($reseau, $comptes, true)) {
                $avert[] = "{$ici} : " . Reseaux::nom($reseau) . " n’est pas connecté pour {$client->name}. Connectez-le avant d’ouvrir la publication dans l’éditeur.";
            }
            unset($g);
        }

        $publications = array_values(array_map(function ($g) use ($client) {
            $g['fichiers'] = $this->fichiers($client, $g);
            return $g;
        }, $groupes));

        foreach ($publications as $p) {
            $manquants = array_keys(array_filter($p['fichiers'], fn ($present) => ! $present));
            if ($manquants) {
                $avert[] = 'Ligne ' . $p['lignes'][0] . ' : en attente de ' . implode(', ', $manquants) . ' — le fichier se rattachera dès son dépôt dans la Médiathèque.';
            }
            if ($p['miniature'] !== '' && $p['video'] === '') {
                $avert[] = 'Ligne ' . $p['lignes'][0] . ' : une miniature ne sert qu’aux vidéos ; elle sera ignorée.';
            }
        }

        return ['publications' => $publications, 'erreurs' => $erreurs, 'avertissements' => array_values(array_unique($avert))];
    }

    /**
     * Crée (ou met à jour) les publications en brouillon. Une publication
     * déjà importée — même campagne, même titre, même moment — est mise à
     * jour tant qu'elle n'est pas partie : on peut réimporter un CSV corrigé.
     *
     * @return array{creees: int, mises_a_jour: int, ignorees: int}
     */
    public function importer(Client $client, Campaign $campagne, array $publications, ?User $auteur): array
    {
        $bilan = ['creees' => 0, 'mises_a_jour' => 0, 'ignorees' => 0];

        DB::transaction(function () use ($client, $campagne, $publications, $auteur, &$bilan) {
            $comptes = $client->integrations()->where('active', true)->get()->keyBy('platform');

            foreach ($publications as $p) {
                $post = $p['titre'] !== ''
                    ? $campagne->posts()->where('title', $p['titre'])->where('scheduled_at', $p['quand']->copy()->utc())->first()
                    : null;

                if ($post && ! $post->estModifiable()) {
                    $bilan['ignorees']++;
                    continue;
                }

                $bilan[$post ? 'mises_a_jour' : 'creees']++;
                $post ??= new Post(['client_id' => $client->id, 'campaign_id' => $campagne->id, 'created_by' => $auteur?->id]);

                $post->fill([
                    'title'          => $p['titre'] ?: null,
                    'caption'        => $p['texte'] ?: null,
                    'link_url'       => $p['lien'] ?: null,
                    'scheduled_at'   => $p['quand']->copy()->utc(),
                    'status'         => 'brouillon',
                    'cover_media_id' => null,
                ])->save();

                $post->media()->detach();
                $post->fichiersAttendus()->delete();

                $fichiers = $p['video'] !== '' ? [$p['video']] : $p['images'];
                foreach ($fichiers as $i => $nom) {
                    $post->fichiersAttendus()->create(['client_id' => $client->id, 'nom' => $nom, 'nom_normalise' => FichierAttendu::normaliser($nom), 'role' => 'media', 'position' => $i]);
                }
                if ($p['miniature'] !== '' && $p['video'] !== '') {
                    $post->fichiersAttendus()->create(['client_id' => $client->id, 'nom' => $p['miniature'], 'nom_normalise' => FichierAttendu::normaliser($p['miniature']), 'role' => 'miniature']);
                }

                $post->targets()->whereNotIn('platform', array_keys($p['cibles']))->delete();
                foreach ($p['cibles'] as $reseau => $c) {
                    $post->targets()->updateOrCreate(['platform' => $reseau], [
                        'integration_id'   => $comptes[$reseau]->id ?? null,
                        'format'           => $c['format'],
                        'caption_override' => $c['texte'] ?: null,
                        'options'          => $c['options'],
                        'status'           => 'en_attente',
                    ]);
                }

                $this->attendus->resoudre($post->load('targets'));
            }
        });

        return $bilan;
    }

    // =====================================================================
    // Lecture
    // =====================================================================

    /** @return array<int, array<string, string>>  numéro de ligne (1 = en-tête) => valeurs */
    private function lire(string $contenu, array &$erreurs): array
    {
        $contenu = preg_replace('/^\x{FEFF}/u', '', $contenu);

        if (! mb_check_encoding($contenu, 'UTF-8')) {
            // Excel sur Windows enregistre parfois en Windows-1252.
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        // Excel en français sépare par « ; » : on prend le séparateur de l'en-tête.
        $premiere   = strtok($contenu, "\n");
        $separateur = substr_count($premiere, ';') > substr_count($premiere, ',') ? ';' : ',';

        $flux = fopen('php://temp', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        $entete = array_map(fn ($c) => mb_strtolower(trim((string) $c)), fgetcsv($flux, null, $separateur, '"', '') ?: []);
        $entete = array_map(fn ($c) => strtr($c, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', ' ' => '_']), $entete);

        foreach (['date', 'heure', 'reseau'] as $obligatoire) {
            if (! in_array($obligatoire, $entete, true)) {
                $erreurs[] = "Colonne « {$obligatoire} » manquante. Partez du gabarit à télécharger.";
            }
        }
        if ($erreurs) {
            return [];
        }

        $inconnues = array_diff(array_filter($entete), self::COLONNES);
        if ($inconnues) {
            $erreurs[] = 'Colonnes inconnues : ' . implode(', ', $inconnues) . '. Colonnes possibles : ' . implode(', ', self::COLONNES) . '.';
            return [];
        }

        $lignes = [];
        $n = 1;
        while (($valeurs = fgetcsv($flux, null, $separateur, '"', '')) !== false) {
            $n++;
            if ($valeurs === [null] || implode('', $valeurs) === '') {
                continue;   // ligne vide
            }
            $ligne = array_fill_keys(self::COLONNES, '');
            foreach ($entete as $i => $col) {
                if ($col !== '') {
                    $ligne[$col] = (string) ($valeurs[$i] ?? '');
                }
            }
            $lignes[$n] = $ligne;
        }

        if (! $lignes) {
            $erreurs[] = 'Le fichier ne contient aucune publication.';
        } elseif (count($lignes) > 500) {
            $erreurs[] = 'Plus de 500 lignes : découpez le fichier.';
        }

        return $lignes;
    }

    private function moment(string $date, string $heure, string $fuseau): ?Carbon
    {
        $date  = trim($date);
        $heure = str_replace(['h', 'H'], ':', trim($heure));
        $heure = preg_match('/^\d{1,2}:$/', $heure) ? $heure . '00' : $heure;

        foreach (['Y-m-d', 'd/m/Y', 'Y/m/d'] as $fd) {
            foreach (['H:i', 'G:i', 'H:i:s'] as $fh) {
                try {
                    $c = Carbon::createFromFormat("!{$fd} {$fh}", "{$date} {$heure}", $fuseau);
                    if ($c && $c->format($fd) === $date) {
                        return $c;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return null;
    }

    private function format(string $reseau, string $valeur): ?string
    {
        $formats = Reseaux::CATALOGUE[$reseau]['formats'];
        $v = mb_strtolower(trim($valeur));

        if ($v === '') {
            return array_key_first($formats);
        }

        foreach ($formats as $cle => $nom) {
            if ($v === $cle || $v === mb_strtolower($nom)) {
                return $cle;
            }
        }

        return $v === 'carousel' && isset($formats['carrousel']) ? 'carrousel' : null;
    }

    /** Le texte propre au réseau (mentions et mots-clics ajoutés) et ses options. */
    private function cible(string $reseau, string $format, array $l, string $ici): array
    {
        $avert    = [];
        $texte    = trim($l['texte']);
        $mentions = $this->jetons($l['mentions'], '@');
        $tags     = $this->jetons($l['hashtags'], '#');

        $ajouts = array_values(array_filter([
            implode(' ', array_filter($mentions, fn ($m) => ! str_contains($texte, $m))),
            implode(' ', array_filter($tags, fn ($t) => ! str_contains($texte, $t))),
        ]));
        if ($ajouts) {
            $texte = trim($texte . "\n\n" . implode("\n", $ajouts));
        }

        if ($mentions && $reseau === 'facebook') {
            $avert[] = "{$ici} : Facebook n’identifie pas par @pseudo via l’API — les mentions resteront du texte.";
        }
        if ($mentions && in_array($reseau, ['linkedin', 'linkedin_profil'], true)) {
            $avert[] = "{$ici} : LinkedIn n’identifie pas par @pseudo via l’API — les mentions resteront du texte.";
        }

        $options = [];
        $oui     = in_array(mb_strtolower(trim($l['ia'])), ['oui', 'o', '1', 'true', 'vrai', 'yes'], true);
        $visi    = mb_strtolower(trim($l['visibilite']));

        if ($reseau === 'youtube') {
            $titre = trim($l['titre']);
            if ($titre === '') {
                $avert[] = "{$ici} : YouTube exige un titre — à compléter avant de programmer.";
            }
            $options = [
                'titre'           => mb_substr($titre, 0, 100),
                'confidentialite' => self::VISIBILITE_YOUTUBE[$visi] ?? 'public',
                'ia'              => $oui,
            ];
        } elseif ($reseau === 'tiktok') {
            // TikTok interdit tout choix par défaut : sans visibilité, on laisse vide.
            $options = [
                'confidentialite' => self::VISIBILITE_TIKTOK[$visi] ?? null,
                'ia'              => $oui,
            ];
            $avert[] = "{$ici} : TikTok demande d’accepter sa confirmation musicale dans l’éditeur avant de programmer.";
        } elseif ($reseau === 'instagram') {
            $collab = array_map(fn ($c) => ltrim($c, '@'), $this->jetons($l['collaborateurs'], ''));
            if (count($collab) > 3) {
                $avert[] = "{$ici} : Instagram accepte 3 collaborateurs au plus — seuls les 3 premiers sont gardés.";
            }
            if ($collab && $format === 'story') {
                $avert[] = "{$ici} : Instagram n’accepte pas de collaborateurs sur une story — ignorés.";
                $collab = [];
            }
            $options = array_filter(['collaborateurs' => array_slice($collab, 0, 3)]);
        }

        if (trim($l['collaborateurs']) !== '' && $reseau !== 'instagram') {
            $avert[] = "{$ici} : les collaborateurs n’existent que sur Instagram — ignorés pour " . Reseaux::nom($reseau) . '.';
        }

        return [['format' => $format, 'texte' => $texte, 'options' => $options], $avert];
    }

    /** « bj21 #casino, poker » → ['#bj21', '#casino', '#poker'] (sans doublon). */
    private function jetons(string $valeur, string $prefixe): array
    {
        $jetons = preg_split('/[\s,;]+/u', trim($valeur), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map(
            fn ($j) => $prefixe === '' ? $j : $prefixe . ltrim($j, $prefixe),
            $jetons,
        )));
    }

    /** @return array<string, bool>  nom de fichier => déjà dans la médiathèque ? */
    private function fichiers(Client $client, array $g): array
    {
        $noms = array_filter(array_merge($g['video'] !== '' ? [$g['video']] : $g['images'], $g['video'] !== '' && $g['miniature'] !== '' ? [$g['miniature']] : []));
        if (! $noms) {
            return [];
        }

        $presents = $client->mediaAssets()->pluck('original_name')->map(fn ($n) => FichierAttendu::normaliser($n))->flip();

        $etat = [];
        foreach ($noms as $nom) {
            $etat[$nom] = $presents->has(FichierAttendu::normaliser($nom));
        }

        return $etat;
    }
}
