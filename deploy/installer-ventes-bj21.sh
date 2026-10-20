#!/bin/bash
# Installe le relais des ventes de bj21rules.com vers /gestion (onglet Demandes).
#
#   bash deploy/installer-ventes-bj21.sh              # installe, et LISTE les ventes passées
#   bash deploy/installer-ventes-bj21.sh rattraper    # installe, et ENVOIE les ventes passées
#
# À lancer APRÈS deploy/deployer.sh : /gestion doit avoir la migration des
# champs de vente (2026_10_19_100004), sinon il refuserait les montants.
# Réutilise la clé STUDIOMACHINE_API_KEY déjà présente dans wp-config.php
# (celle des pages vues) : aucune nouvelle clé.

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243
ICI="$(cd "$(dirname "$0")" && pwd)"
MODE="${1:-liste}"

scp -q -P $PORT "$ICI/bj21/bj21-ventes-gestion.php" "$SERVEUR:/tmp/bj21-ventes-gestion.php"

ssh -p $PORT "$SERVEUR" "MODE=$MODE bash -s" <<'DISTANT'
set -euo pipefail
GESTION=/home/studiomachine/gestion-app
MU=/home/bj21/public_html/wp-content/mu-plugins/bj21-ventes-gestion.php
WP="sudo -u bj21 -H /opt/cpanel/ea-php82/root/usr/bin/php -d memory_limit=512M /usr/local/bin/wp --path=/home/bj21/public_html"

cd $GESTION
sudo -u studiomachine /opt/cpanel/ea-php85/root/usr/bin/php artisan migrate:status 2>/dev/null | grep 2026_10_19_100004 | grep -q Ran \
    || { echo "✗ /gestion n'a pas encore les champs de vente : lancer d'abord deploy/deployer.sh"; rm -f /tmp/bj21-ventes-gestion.php; exit 1; }

/opt/cpanel/ea-php82/root/usr/bin/php -l /tmp/bj21-ventes-gestion.php
mv /tmp/bj21-ventes-gestion.php $MU
chown bj21:bj21 $MU && chmod 644 $MU

echo "→ Ventes passées pas encore transmises :"
if [ "$MODE" = "rattraper" ]; then
    $WP bj21-ventes rattraper 2>&1 | grep -v "^PHP Warning\|^Warning"
else
    $WP bj21-ventes rattraper --essai 2>&1 | grep -v "^PHP Warning\|^Warning"
fi
DISTANT

echo "✓ Relais des ventes installé. Les prochaines ventes arriveront dans https://studiomachine.ca/gestion/leads?client=bj21rules"
