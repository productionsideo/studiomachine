#!/bin/bash
# Déploie /gestion sur studiomachine.ca depuis cette copie locale.
#
#   bash deploy/deployer.sh            # envoie le code, migre, vide les caches
#   bash deploy/deployer.sh --cron     # idem, et remplace le cron par schedule:run
#
# Ce qui n'est JAMAIS envoyé : .env, vendor, storage (journaux, médias),
# bootstrap/cache (généré avec les paquets de dev en local), tests.
# Faire une sauvegarde avant : base + code dans /home/studiomachine/sauvegardes.

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243
APP=/home/studiomachine/gestion-app
WEB=/home/studiomachine/public_html/gestion
PHP=/opt/cpanel/ea-php85/root/usr/bin/php

cd "$(dirname "$0")/.."

echo "→ Envoi du code"
rsync -a --checksum --chown=studiomachine:studiomachine -e "ssh -p $PORT" \
    --exclude .git --exclude .env --exclude vendor --exclude node_modules \
    --exclude storage --exclude bootstrap/cache --exclude database/database.sqlite \
    --exclude public/fichiers --exclude deploy --exclude tests --exclude .phpunit.result.cache \
    ./ "$SERVEUR:$APP/"

echo "→ Migrations, médias, caches"
ssh -p $PORT "$SERVEUR" bash -s <<EOF
set -e
cd $APP
sudo -u studiomachine $PHP artisan migrate --force
sudo -u studiomachine mkdir -p storage/app/medias storage/app/medias-envois
sudo -u studiomachine ln -sfn $APP/storage/app/medias $WEB/fichiers
install -o studiomachine -g studiomachine -m 644 public/assets/dashboard.css $WEB/assets/dashboard.css
sudo -u studiomachine $PHP artisan config:clear
sudo -u studiomachine $PHP artisan view:clear
sudo -u studiomachine $PHP artisan schedule:list
EOF

if [[ "${1:-}" == "--cron" ]]; then
    echo "→ Cron : schedule:run chaque minute (remplace social:synchroniser)"
    ssh -p $PORT "$SERVEUR" "echo '* * * * * $PHP $APP/artisan schedule:run >> /dev/null 2>&1' | crontab -u studiomachine - && crontab -l -u studiomachine"
fi

echo "✓ Déployé. Vérifier : https://studiomachine.ca/gestion/calendrier"
