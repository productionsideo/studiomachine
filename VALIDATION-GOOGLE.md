# Validation Google (YouTube) — projet Google Cloud `studio-machine`

## État au 2026-10-19

- App OAuth **en production** (n'importe quel compte Google peut se connecter, avec l'écran « application non validée » ; plus d'expiration 7 jours).
- Domaine studiomachine.ca validé dans Search Console (TXT `google-site-verification` chez Cloudflare — ne pas le supprimer).
- Branding **validé et publié**.
- **Demande de validation envoyée le 2026-10-19** (justification ci-dessous + vidéo https://youtu.be/roFmlH3MJXc, non répertoriée sur la chaîne Productions IDEO). État : « en cours d'examen » dans Centre de validation ; réponses de Google par courriel à info@studiomachine.ca / brunochasse@gmail.com.
- Vidéo test publiée pendant la démo : https://www.youtube.com/watch?v=UGmnLkv24qo (privée) — à garder jusqu'à la réponse de Google.
- **Audit YouTube API Services envoyé le 2026-10-19** (formulaire yt_api_form ; projet 379016184494 ; quota par défaut + videos.insert 16 000 u/j ; accord conservation des stats 36 mois ; compte examinateur google-review@studiomachine.ca, rôle Client sur Productions IDEO). Preuves et diagrammes : ~/Desktop/demo-google/preuves-audit/. Réponse par courriel à info@studiomachine.ca (souvent plusieurs semaines). Tant que l'audit n'est pas accepté, les vidéos envoyées par l'API restent privées.
- Point faible connu : à la déconnexion, le jeton est révoqué chez Google mais les statistiques déjà collectées restent ; à supprimer si l'audit le reproche.

## Justification (Accès aux données → « Comment seront utilisés ces niveaux d'accès ? », 922/1000)

```
Studio Machine (studiomachine.ca/gestion) is a social media scheduling tool our agency team uses to publish and report on video campaigns for our clients, who connect their own YouTube channel. youtube.upload is used only to upload the videos our team schedules for the connected channel (videos.insert, resumable upload) and to set the thumbnail chosen for that video (thumbnails.set). No other write action: no deletion, no editing of existing videos, no comments. youtube.readonly is used to identify the connected channel (channels.list: name and id) and to show the client the statistics of their own channel and videos in the dashboard (subscribers, views, likes, comments via channels.list, playlistItems.list, videos.list). Narrower scopes are insufficient: no read-only scope allows uploading, and youtube.upload does not allow reading channel or video statistics. Data is never sold or shared with third parties.
```

## Vidéo de démonstration (à enregistrer par Bruno)

Exigences Google : vidéo YouTube **non répertoriée**, en anglais ou sous-titrée/commentée en anglais de préférence, interface lisible. Elle doit montrer le **client ID OAuth** utilisé et l'écran de consentement complet.

Déroulé (2 à 4 min, enregistrement d'écran macOS : Cmd+Maj+5) :

1. **Barre d'adresse visible** : ouvrir https://studiomachine.ca/gestion, se connecter.
2. Aller dans **Intégrations**, cliquer « Connecter » sur YouTube pour un client.
3. Sur la page Google, **montrer l'URL** (zoomer sur `client_id=…` dans la barre d'adresse) — c'est ainsi que Google relie la vidéo au projet.
4. Choisir le compte, passer l'écran « application non validée » (Paramètres avancés → Continuer) — Google demande qu'il apparaisse.
5. **Écran de consentement** : laisser lisibles le nom « Studio Machine » et les deux accès (afficher le compte YouTube, gérer les vidéos), puis Continuer.
6. Retour dans /gestion : la fiche YouTube affiche le nom de la chaîne → *youtube.readonly (channels.list)*.
7. **Publication** : créer une publication YouTube avec une vidéo et une miniature, la programmer ou la publier ; montrer qu'elle passe « Publiée », ouvrir le lien → la vidéo est sur la chaîne → *youtube.upload (videos.insert, thumbnails.set)*.
8. **Statistiques** : ouvrir le Résumé du client, montrer abonnés / vues de la chaîne et des vidéos → *youtube.readonly (videos.list)*.

Mettre la vidéo en ligne en **non répertoriée**, puis donner le lien à Claude pour remplir la justification + le lien et soumettre.
