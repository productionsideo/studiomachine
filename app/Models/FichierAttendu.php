<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un fichier nommé dans un import CSV, pas encore déposé dans la médiathèque. */
class FichierAttendu extends Model
{
    protected $table = 'post_fichiers_attendus';

    protected $guarded = ['id'];

    public function post() { return $this->belongsTo(Post::class); }

    /** La clé de rapprochement : « Vidéos/Règle 01.MP4 » et « règle 01.mp4 » sont le même fichier. */
    public static function normaliser(string $nom): string
    {
        return mb_strtolower(trim(basename(str_replace('\\', '/', $nom))));
    }
}
