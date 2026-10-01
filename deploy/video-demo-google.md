# Vidéo de démonstration — vérification Google (YouTube)

Google la demande pour vérifier une application qui utilise des permissions
sensibles (`youtube.upload`). Elle doit montrer, **en une seule prise
continue**, le parcours OAuth complet puis l'usage de chaque permission.

## Avant d'enregistrer

- [ ] Projet Google Cloud créé, YouTube Data API v3 activée
- [ ] Écran de consentement : **seulement** `youtube.upload` et `youtube.readonly` (pas `yt-analytics.readonly`, que l'app n'utilise pas encore)
- [ ] Écran de consentement rempli : nom **Studio Machine**, logo, domaine `studiomachine.ca`,
      page d'accueil `https://studiomachine.ca/en/plateforme/`,
      confidentialité `https://studiomachine.ca/en/confidentialite/`,
      conditions `https://studiomachine.ca/en/conditions/`
- [ ] `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` dans `gestion-app/.env`
- [ ] Le compte Google de la chaîne de test ajouté aux « utilisateurs de test »
- [ ] Une publication prête : client avec une vidéo 9:16 en médiathèque
- [ ] Navigateur en **anglais** (Google le demande pour l'écran de consentement),
      fenêtre propre, onglets et favoris masqués, zoom 100 %

Enregistrer : **Cmd + Maj + 5** → « Enregistrer une partie de l'écran », la
fenêtre du navigateur **avec la barre d'adresse visible**. Pas de son nécessaire ;
des sous-titres ou une voix en anglais aident le réviseur.

## Scénario (2 à 4 minutes)

| # | À l'écran | À montrer / dire (en anglais) |
|---|---|---|
| 1 | `studiomachine.ca/en/plateforme/` puis les liens Privacy et Terms en pied de page | « Studio Machine is a video production studio. Our management platform lets our team publish videos to our clients' YouTube channels. » |
| 2 | `studiomachine.ca/gestion` → connexion → **Integrations** → client → **YouTube** | Le bouton « Connecter YouTube ». |
| 3 | Clic sur **Connecter** → écran de consentement Google | **Faire une pause de 3 s sur la barre d'adresse** : le `client_id=…apps.googleusercontent.com` doit être lisible. Montrer le nom « Studio Machine » et les 2 permissions. |
| 4 | Choisir le compte, accepter | Retour sur /gestion : « Compte connecté » avec le nom de la chaîne. → montre `youtube.readonly` (lecture du nom de la chaîne). |
| 5 | **Calendrier** → nouvelle publication → vidéo 9:16, YouTube coché, format Short, titre → **Publier maintenant** | « youtube.upload is used only to upload the videos our client scheduled. » |
| 6 | Fiche de la publication → état « Publiée » → lien **Voir sur YouTube** → la vidéo dans YouTube Studio | La vidéo apparue sur la chaîne. |
| 7 | Intégrations → **Déconnecter**, puis `myaccount.google.com/permissions` | Montrer qu'on peut retirer l'accès. |

## À l'envoi

- Mettre la vidéo sur YouTube en **non répertoriée** et coller le lien dans le formulaire de vérification.
- Dans la justification de chaque permission, reprendre les phrases des plans 4 et 5.
- Même principe plus tard pour **Meta** (un screencast par permission) et **TikTok**
  (montrer en plus le choix de confidentialité et la case de consentement musical).
