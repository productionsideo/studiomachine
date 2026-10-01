#!/bin/bash
# Affiche ce que Meta répond pour chaque envoi « en traitement » dans /gestion.
# Lecture seule ; aucun jeton n'est affiché.
#
#   bash deploy/etat-meta.sh

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243
APP=/home/studiomachine/gestion-app
PHP=/opt/cpanel/ea-php85/root/usr/bin/php

# Une seule ligne : tinker lit son entrée ligne par ligne.
CODE='echo "Derniers envois :\n"; foreach (App\Models\PostTarget::latest("updated_at")->take(8)->get() as $c) { echo "#{$c->id} {$c->platform} {$c->format} statut={$c->status} job={$c->external_job_id} " . ($c->last_error ?? "") . "\n"; if ($c->status !== "en_cours" || ! $c->external_job_id) continue; try { echo json_encode(app(App\Services\Publication\ClientGraph::class)->appeler("/" . $c->external_job_id, $c->integration->access_token, ["fields" => $c->platform === "instagram" ? "status_code,status" : "status,permalink_url"]), JSON_PRETTY_PRINT), "\n"; } catch (Throwable $e) { echo "  Erreur Meta : ", $e->getMessage(), "\n"; } }'

echo "$CODE" | ssh -p $PORT "$SERVEUR" "cd $APP && sudo -u studiomachine $PHP artisan tinker" 2>&1 | grep -v -e "terminal type" -e "dumb terminal"
