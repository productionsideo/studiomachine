#!/bin/bash
# Restaure l'extension BJ21 Membre vidée par Imunify360 le 2026-10-19 à 9 h 27
# (fausse alerte « php.bkdr.remote » : le fichier mis en quarantaine est
# identique, octet pour octet, à la version enregistrée dans git le 29 sept.).
#
#   bash deploy/restaurer-bj21-membre.sh              # restaure seulement
#   bash deploy/restaurer-bj21-membre.sh exception    # + exception Imunify
#
# Remet la version de git (0.22.1, commit 537bc7c) en place, vérifie la
# syntaxe, réactive l'extension et vide le cache. Avec « exception », ajoute
# une exception Imunify pour ce seul fichier, sans quoi le prochain scan risque
# de le vider de nouveau. (Pas de question au clavier : lancé par « ! » dans
# Claude Code, le script n'a pas de clavier et s'arrêterait en silence.)

set -euo pipefail

SERVEUR="root@odedi193785.mywhc.ca"
PORT=2243

EXCEPTION=""
[ "${1:-}" = "exception" ] && EXCEPTION=o
echo "→ Restauration de BJ21 Membre${EXCEPTION:+ (avec exception Imunify)}"

ssh -p $PORT "$SERVEUR" "EXCEPTION='$EXCEPTION' bash -s" <<'DISTANT'
set -euo pipefail
G=/home/bj21/git/bj21rules
P=/home/bj21/public_html/wp-content/plugins/bj21-membre/bj21-membre.php
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
# 128 Mo ne suffisent pas à WP-CLI pour charger WooCommerce, WPML et fr_FR.
WP="sudo -u bj21 -H $PHP -d memory_limit=512M /usr/local/bin/wp --path=/home/bj21/public_html"
ATTENDU=d5e367996077aed86b35e166087055ff56a3d88a25c07a0d03a9b281fb4654f3

cd $G
git checkout -- plugins/bj21-membre/bj21-membre.php
[ "$(sha256sum plugins/bj21-membre/bj21-membre.php | cut -d' ' -f1)" = "$ATTENDU" ] \
    || { echo "✗ La version de git n'est pas celle attendue — rien d'autre n'a été touché."; exit 1; }

$PHP -l plugins/bj21-membre/bj21-membre.php
cp -p plugins/bj21-membre/bj21-membre.php "$P"
chown bj21:bj21 "$P"
cp -p plugins/bj21-membre/bj21-membre.php /home/bj21/bj21-backups/membre-0.22.1-20260930-113903/bj21-membre.php

if [[ "$EXCEPTION" =~ ^[oOyY] ]]; then
    imunify360-agent malware ignore add "$P" >/dev/null && echo "✓ Exception Imunify : $P"
fi

$WP plugin activate bj21-membre
$WP plugin list --name=bj21-membre --fields=name,status,version
$WP litespeed-purge all >/dev/null 2>&1 || true
DISTANT

for u in https://bj21rules.com/ https://bj21rules.com/mon-compte/; do
    echo "  $(curl -s -o /dev/null -w '%{http_code}' "$u")  $u"
done
echo "✓ BJ21 Membre restaurée. Vérifier l'espace membre : https://bj21rules.com/mon-compte/"
