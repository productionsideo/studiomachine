#!/bin/bash
# Inscrit le Client ID et le secret d'une application LinkedIn dans le .env
# du serveur, puis vide le cache de configuration.
#
#   bash deploy/env-linkedin.sh profil     # app « Studio Machine - Profils »
#   bash deploy/env-linkedin.sh pages      # app « Studio Machine - Pages »
#
# Les valeurs sont demandées au clavier (le secret ne s'affiche pas) et
# passent par l'entrée de ssh : elles n'apparaissent ni dans l'historique, ni
# dans la liste des processus. Le .env est sauvegardé avant d'être modifié.

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243
APP=/home/studiomachine/gestion-app
PHP=/opt/cpanel/ea-php85/root/usr/bin/php

case "${1:-}" in
    profil) PREFIXE=LINKEDIN_PROFIL ;;
    pages)  PREFIXE=LINKEDIN_PAGES ;;
    *) echo "Usage : bash deploy/env-linkedin.sh profil|pages"; exit 1 ;;
esac

read -r -p "Client ID ($1) : " ID
read -r -s -p "Primary Client Secret ($1) : " SECRET
echo

if [[ -z "$ID" || -z "$SECRET" ]]; then
    echo "Client ID et secret obligatoires."; exit 1
fi

printf '%s\n%s\n' "$ID" "$SECRET" | ssh -p $PORT "$SERVEUR" \
    "PREFIXE=$PREFIXE APP=$APP PHP=$PHP bash -c '
set -e
read -r ID; read -r SECRET
cd \$APP
cp -p .env .env.avant-linkedin-\$(date +%Y%m%d%H%M%S)
grep -v -E \"^\${PREFIXE}_CLIENT_(ID|SECRET)=\" .env > .env.nouveau || true
printf \"%s_CLIENT_ID=%s\n%s_CLIENT_SECRET=%s\n\" \"\$PREFIXE\" \"\$ID\" \"\$PREFIXE\" \"\$SECRET\" >> .env.nouveau
cat .env.nouveau > .env && rm .env.nouveau
sudo -u studiomachine \$PHP artisan config:clear
grep -c \"^\${PREFIXE}_CLIENT_\" .env | sed \"s/^/  lignes \${PREFIXE}_CLIENT_* dans .env : /\"
'"

echo "✓ ${PREFIXE} inscrit. Vérifier : https://studiomachine.ca/gestion/integrations"
