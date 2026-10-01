#!/bin/bash
# Importe un plan de campagne dans /gestion en production, en BROUILLON.
#
#   bash deploy/importer-campagne.sh "/chemin/vers/plan.json" [--essai]
#
# Le dossier du plan (plan.json + médias) est copié sur le serveur, puis
# `artisan campagne:importer` l'intègre. Relançable sans doublon.

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243
APP=/home/studiomachine/gestion-app
PHP=/opt/cpanel/ea-php85/root/usr/bin/php

PLAN="$1"; shift || true
DOSSIER="$(cd "$(dirname "$PLAN")" && pwd)"
CIBLE="$APP/storage/app/imports/$(basename "$DOSSIER")"

echo "→ Copie du plan et des médias"
ssh -p $PORT "$SERVEUR" "sudo -u studiomachine mkdir -p '$CIBLE'"
rsync -a --chown=studiomachine:studiomachine --exclude '._*' --exclude .DS_Store -e "ssh -p $PORT" "$DOSSIER/" "$SERVEUR:$CIBLE/"

echo "→ Import"
ssh -p $PORT "$SERVEUR" "cd $APP && sudo -u studiomachine $PHP artisan campagne:importer '$CIBLE/$(basename "$PLAN")' $*"
