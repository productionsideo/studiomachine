#!/bin/bash
# Installe la veille de bj21rules.com sur le serveur : un contrôle toutes les
# 10 minutes, un courriel à info@productionsideo.com en cas de panne.
#
#   bash deploy/installer-veille-bj21.sh
#
# Relevé au passage : la liste des extensions actives À CET INSTANT devient
# la liste attendue. À relancer après avoir ajouté ou retiré une extension,
# sinon la veille signalera son absence.

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243
ICI="$(cd "$(dirname "$0")" && pwd)/veille"

ssh -p $PORT "$SERVEUR" 'mkdir -p /root/veille && chmod 700 /root/veille'
scp -q -P $PORT "$ICI/veille-site.sh" "$ICI/bj21.conf" "$SERVEUR:/root/veille/"
scp -q -P $PORT "$ICI/bj21-alertes.php" "$SERVEUR:/home/bj21/public_html/wp-content/mu-plugins/bj21-alertes.php"

ssh -p $PORT "$SERVEUR" bash -s <<'DISTANT'
set -euo pipefail
chmod 700 /root/veille/veille-site.sh
chown bj21:bj21 /home/bj21/public_html/wp-content/mu-plugins/bj21-alertes.php
/opt/cpanel/ea-php82/root/usr/bin/php -l /home/bj21/public_html/wp-content/mu-plugins/bj21-alertes.php

sudo -u bj21 -H /opt/cpanel/ea-php82/root/usr/bin/php -d memory_limit=512M /usr/local/bin/wp \
    --path=/home/bj21/public_html --skip-plugins --skip-themes \
    plugin list --status=active --field=file > /root/veille/bj21-extensions.txt
echo "→ $(wc -l < /root/veille/bj21-extensions.txt) extensions actives relevées"

echo '*/10 * * * * root /root/veille/veille-site.sh /root/veille/bj21.conf >/dev/null 2>&1' > /etc/cron.d/veille-bj21
chmod 644 /etc/cron.d/veille-bj21

/root/veille/veille-site.sh /root/veille/bj21.conf --essai
DISTANT

echo "✓ Veille installée. Un courriel d'essai doit arriver à info@productionsideo.com."
