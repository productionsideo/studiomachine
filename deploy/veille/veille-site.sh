#!/bin/bash
# Veille d'un site WordPress hébergé sur ce serveur. Lancée toutes les 10 min
# par /etc/cron.d/veille-<site>, en root :
#
#   /root/veille/veille-site.sh /root/veille/<site>.conf
#
# Pourquoi hors de WordPress : la panne du 2026-10-19 (Imunify360 a vidé
# bj21-membre.php, WordPress l'a désactivée en silence) ne produisait ni
# erreur fatale ni page en erreur — le site répondait 200. Seul un regard
# extérieur voit qu'une extension manque ou qu'un fichier est vide.
#
# Un courriel au début d'une panne, un rappel toutes les RAPPEL secondes tant
# qu'elle dure, un « Rétabli » à la fin. Les événements ponctuels (détection
# Imunify, erreur fatale PHP) partent à part, au plus une fois par heure.
# Rien quand tout va bien.

set -uo pipefail

CONF="${1:?Usage : veille-site.sh <fichier.conf>}"
# shellcheck source=/dev/null
source "$CONF"
# Attendus dans le .conf : SITE NOM URLS MARQUES WP_RACINE WP_UTILISATEUR PHP_CLI
#                          CPANEL_UTILISATEUR DESTINATAIRE EXPEDITEUR
RAPPEL=${RAPPEL:-21600}
ETAT_DIR=${ETAT_DIR:-/root/veille/etat-$SITE}
LISTE=${LISTE:-/root/veille/$SITE-extensions.txt}
mkdir -p "$ETAT_DIR"

exec 9>"$ETAT_DIR/verrou"
flock -n 9 || exit 0

PROBLEMES=()    # l'état du site : dure tant que ce n'est pas réparé
EVENEMENTS=()   # ce qui vient de se passer : signalé une fois
WP="sudo -u $WP_UTILISATEUR -H $PHP_CLI -d memory_limit=512M /usr/local/bin/wp --path=$WP_RACINE --skip-plugins --skip-themes"

# 1. Les pages : 200, pas d'erreur critique, et les marques des extensions
#    maison présentes (absentes = extension désactivée). Un paramètre unique
#    contourne le cache LiteSpeed, qui servirait sinon une page d'avant la panne.
for url in $URLS; do
    corps=$(curl -s -m 30 -A "veille-$SITE" -w '\n%{http_code}' "$url?veille=$(date +%s%N)") || corps=$'\n000'
    code=${corps##*$'\n'}
    if [ "$code" != "200" ]; then
        PROBLEMES+=("La page $url répond $code.")
        continue
    fi
    if grep -qi -E "erreur critique|critical error" <<<"$corps"; then
        PROBLEMES+=("La page $url affiche « erreur critique ».")
    fi
done
accueil=$(curl -s -m 30 -A "veille-$SITE" "${URLS%% *}?veille=$(date +%s%N)")
for marque in $MARQUES; do
    grep -q "$marque" <<<"$accueil" || PROBLEMES+=("L'accueil ne contient plus « $marque » : l'extension est peut-être désactivée.")
done

# 2. Les extensions actives : toutes celles relevées à l'installation.
actives=$($WP option get active_plugins --format=json 2>/dev/null)
if [ -z "$actives" ]; then
    PROBLEMES+=("WP-CLI ne répond pas (WordPress ne se charge pas en ligne de commande).")
else
    while read -r ext; do
        [ -z "$ext" ] && continue
        grep -qF "\"${ext//\//\\/}\"" <<<"$actives" || PROBLEMES+=("Extension désactivée : $ext")
    done < "$LISTE"
fi

# 3. Aucun fichier PHP vide dans le code maison.
while read -r vide; do
    [ -n "$vide" ] && PROBLEMES+=("Fichier vide : $vide")
done < <(find "$WP_RACINE/wp-content/plugins" "$WP_RACINE/wp-content/mu-plugins" -name '*.php' -size 0 2>/dev/null)

# 4. Imunify360 : une détection de plus qu'au dernier passage.
nb=$(imunify360-agent malware malicious list --user "$CPANEL_UTILISATEUR" --limit 1 --json 2>/dev/null | grep -o '"max_count": *[0-9]*' | grep -o '[0-9]*$')
if [ -n "$nb" ]; then
    avant=$(cat "$ETAT_DIR/imunify" 2>/dev/null || echo "$nb")
    if [ "$nb" -gt "$avant" ]; then
        derniers=$(imunify360-agent malware malicious list --user "$CPANEL_UTILISATEUR" --limit $((nb - avant)) 2>/dev/null | awk 'NR>1' | grep -o '/home/[^ ]*' | sort -u | tr '\n' ' ')
        EVENEMENTS+=("Imunify360 a $((nb - avant)) nouvelle(s) détection(s) : ${derniers}— un fichier a peut-être été vidé. Vérifier : imunify360-agent malware malicious list --user $CPANEL_UTILISATEUR")
    fi
    echo "$nb" > "$ETAT_DIR/imunify"
fi

# 5. Les erreurs fatales PHP apparues depuis le dernier passage.
journal="$WP_RACINE/error_log"
if [ -f "$journal" ]; then
    taille=$(stat -c %s "$journal")
    lu=$(cat "$ETAT_DIR/journal" 2>/dev/null || echo "$taille")
    [ "$taille" -lt "$lu" ] && lu=0   # journal tourné
    fatales=$(tail -c +$((lu + 1)) "$journal" | grep "PHP Fatal" | tail -3 | cut -c1-400)
    [ -n "$fatales" ] && EVENEMENTS+=("Erreur(s) fatale(s) PHP dans error_log :"$'\n'"$fatales")
    echo "$taille" > "$ETAT_DIR/journal"
fi

# --- Courriel, seulement quand l'état change (ou en rappel) ---------------
envoyer() {
    local sujet="$1" corps="$2"
    {
        echo "From: $EXPEDITEUR"
        echo "To: $DESTINATAIRE"
        echo "Subject: =?UTF-8?B?$(printf '%s' "$sujet" | base64 -w0)?="
        echo "MIME-Version: 1.0"
        echo "Content-Type: text/plain; charset=UTF-8"
        echo "Content-Transfer-Encoding: 8bit"
        echo
        printf '%s\n' "$corps"
        echo
        echo "-- "
        echo "Veille $NOM, serveur $(hostname), $(date '+%Y-%m-%d %H:%M %Z')."
        echo "Script : /root/veille/veille-site.sh — réglages : $CONF"
    } | ${SENDMAIL:-/usr/sbin/sendmail} -t -f "$(grep -o '[^<> ]*@[^<> ]*' <<<"$EXPEDITEUR")" 2>/dev/null
}

if [ "${2:-}" = "--essai" ]; then
    envoyer "[Essai] Veille $NOM" "La veille de $NOM est en place. Ce courriel confirme que les alertes arrivent bien ici."
    echo "✓ Courriel d'essai envoyé à $DESTINATAIRE"
fi

maintenant=$(date +%s)
liste_evenements=""
[ ${#EVENEMENTS[@]} -gt 0 ] && liste_evenements=$'\n\nÀ signaler aussi :\n'$(printf -- '- %s\n' "${EVENEMENTS[@]}")

if [ ${#PROBLEMES[@]} -gt 0 ]; then
    liste=$(printf -- '- %s\n' "${PROBLEMES[@]}")
    signature=$(md5sum <<<"$liste" | cut -c1-12)
    precedente=$(cat "$ETAT_DIR/signature" 2>/dev/null || echo ok)
    dernier=$(cat "$ETAT_DIR/envoye" 2>/dev/null || echo 0)
    if [ "$signature" != "$precedente" ] || [ $((maintenant - dernier)) -ge "$RAPPEL" ] || [ -n "$liste_evenements" ]; then
        [ "$precedente" = "ok" ] && titre="PANNE" || titre="Toujours en panne"
        envoyer "[$titre] $NOM" "Problème(s) détecté(s) sur $NOM :"$'\n\n'"$liste$liste_evenements"
        echo "$maintenant" > "$ETAT_DIR/envoye"
        [ "$precedente" = "ok" ] && date '+%Y-%m-%d %H:%M' > "$ETAT_DIR/depuis"
    fi
    echo "$signature" > "$ETAT_DIR/signature"
    printf '%s\n' "$liste$liste_evenements"
    exit 1
fi

if [ "$(cat "$ETAT_DIR/signature" 2>/dev/null || echo ok)" != "ok" ]; then
    envoyer "[Rétabli] $NOM" "$NOM fonctionne de nouveau (panne signalée depuis le $(cat "$ETAT_DIR/depuis" 2>/dev/null || echo '?')).$liste_evenements"
    liste_evenements=""
fi
echo ok > "$ETAT_DIR/signature"

if [ -n "$liste_evenements" ]; then
    dernier=$(cat "$ETAT_DIR/evenement" 2>/dev/null || echo 0)
    if [ $((maintenant - dernier)) -ge 3600 ]; then
        envoyer "[Alerte] $NOM" "Le site répond normalement, mais :${liste_evenements#*:}"
        echo "$maintenant" > "$ETAT_DIR/evenement"
    fi
    printf '%s\n' "${liste_evenements#*:}"
    exit 1
fi
echo "✓ $NOM : tout va bien."
