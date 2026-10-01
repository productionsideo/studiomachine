# Publication sur les réseaux sociaux

Planifier et publier des campagnes sur Facebook, Instagram, YouTube et TikTok
depuis `/gestion`. L'équipe Studio Machine rédige et programme ; les clients
consultent (calendrier, campagnes, état de chaque publication).

## Ce qui existe

| Écran | Rôle |
|---|---|
| **Calendrier** | Vue du mois, tous clients ou un seul ; « + » sur un jour pour programmer |
| **Éditeur** | Texte commun + texte propre à chaque réseau, médias, format par réseau, date. Vérifie les règles de chaque réseau **avant** de programmer |
| **Campagnes** | Regroupe les publications ; son `utm_campaign` relie les demandes reçues à la campagne (visites, demandes, conversion) |
| **Médiathèque** | Images et vidéos du client ; envoi en morceaux (le serveur plafonne à 25 Mo par requête) ; PNG/WebP convertis en JPEG (exigence d'Instagram) |
| **Intégrations** | Bouton « Connecter » par OAuth (Meta, Google, TikTok) au lieu du jeton collé à la main |

## Comment ça publie

`php artisan schedule:run` (cron, chaque minute) → `publications:envoyer` :

1. **Démarrer** les cibles dues (une cible = une publication × un réseau). Chaque
   cible est *réservée* par une mise à jour conditionnelle avant d'être touchée :
   deux passes simultanées ne publient jamais deux fois.
2. **Poursuivre** celles que le réseau traite (vidéos) : on repasse chaque minute,
   abandon au bout de 2 h.

Les échecs suivent une règle stricte (`app/Services/Publication/Etape.php`) :
on ne retente seul **que** si l'on est certain que rien n'est sorti. Sinon la
cible passe en échec et un humain vérifie sur le réseau avant de cliquer « Relancer ».

| Réseau | Classe | Mécanique |
|---|---|---|
| Facebook | `PublieurFacebook` | `/feed`, `/photos`, `/videos` (file_url), Reels en 3 temps (start → rupload file_url → finish) |
| Instagram | `PublieurInstagram` | conteneur → `status_code` FINISHED → `media_publish` ; carrousel : enfants puis parent |
| YouTube | `PublieurYoutube` | upload resumable, fichier envoyé en flux |
| TikTok | `PublieurTiktok` | Direct Post, `FILE_UPLOAD` en morceaux (pas de vérification de domaine requise) |

Les médias sont servis publiquement sous `/gestion/fichiers/<nom aléatoire de 40 caractères>` :
Instagram et Facebook vont les chercher eux-mêmes.

## Déploiement (première fois)

```bash
# Sur le serveur, compte studiomachine, dans /home/studiomachine/gestion-app
php artisan migrate --force          # 3 tables : campaigns, media_assets, posts (+ post_media, post_targets)
mkdir -p storage/app/medias
ln -s /home/studiomachine/gestion-app/storage/app/medias /home/studiomachine/public_html/gestion/fichiers
cp public/assets/dashboard.css /home/studiomachine/public_html/gestion/assets/
php artisan config:clear && php artisan view:clear
```

**Cron** — remplacer la ligne `social:synchroniser` par une seule ligne, chaque minute :

```
* * * * * /opt/cpanel/ea-php85/root/usr/bin/php /home/studiomachine/gestion-app/artisan schedule:run >> /dev/null 2>&1
```

La synchronisation des commentaires reste à 15 minutes (`routes/console.php`).

**.env** — ajouter au besoin : `META_LOGIN_CONFIG_ID`, `GOOGLE_CLIENT_ID/SECRET`,
`TIKTOK_CLIENT_KEY/SECRET`. `MEDIAS_URL` vaut par défaut `APP_URL/fichiers`.

## Ce qui ne dépend pas du code : les approbations

Vérifié sur la documentation officielle le 2026-09-30.

| Réseau | Sans approbation | Pour publier chez les clients |
|---|---|---|
| **Meta** | Accès standard : seulement les comptes ayant un rôle sur l'app | Vérification d'entreprise + App Review (accès avancé) de `pages_manage_posts`, `publish_video`, `instagram_content_publish`, etc. Un screencast par permission |
| **YouTube** | Les vidéos envoyées par l'API sont **forcées en privé** | Vérification de l'écran de consentement + *Audit and Quota Extension Form*. Quota par défaut : 100 mises en ligne / jour |
| **TikTok** | Publication **privée** seulement, 5 comptes / 24 h | Revue de l'app (quelques jours à 2 semaines) + audit Content Posting. L'interface de l'éditeur respecte déjà leurs règles (confidentialité sans défaut, interactions décochées, divulgation commerciale, consentement musical) |

URL de retour OAuth à déclarer chez chaque fournisseur :

- `https://studiomachine.ca/gestion/integrations/meta/retour`
- `https://studiomachine.ca/gestion/integrations/youtube/retour`
- `https://studiomachine.ca/gestion/integrations/tiktok/retour`

## Limites connues / à faire

- Statistiques par publication (vues, portée) : la table `social_metrics` existe pour les
  capsules ; il reste à y rattacher les publications (phase suivante).
- Validation d'une publication par le client avant envoi : non faite.
- Instagram : la limite de 100 publications / 24 h n'est pas vérifiée d'avance
  (`content_publishing_limit`) — Meta refusera au-delà, l'échec sera visible.
- Facebook vidéo (non-Reel) via `file_url` : paramètre largement utilisé mais non
  confirmé sur la page de doc actuelle.
