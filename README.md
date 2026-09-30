# Studio Machine — Gestion

Dashboard client de Studio Machine (Laravel 13, PHP 8.5), en ligne sur
**studiomachine.ca/gestion**.

## Fonctions actuelles

- **Demandes reçues** (leads) : ingestion depuis les sites clients par API (`/api/v1`, clé `X-API-Key`), suivi commercial, export.
- **Capsules** : performance des vidéos publiées par réseau (vues, portée, écoute).
- **Commentaires** : boîte de réception Facebook / Instagram, réponse depuis le dashboard.
- **Assistant Claude** : tri et brouillons de réponse, validation humaine.
- **Intégrations** : comptes Analytics et réseaux sociaux par client.
- **Clients et accès** : multi-client, rôles admin / client.

## Déploiement (cPanel, compte `studiomachine`)

| Élément | Emplacement serveur |
|---|---|
| Application (ce dépôt) | `/home/studiomachine/gestion-app` — hors `public_html`, le `.env` n'est jamais servi |
| Contrôleur frontal | `/home/studiomachine/public_html/gestion/` ← `deploy/public_html-gestion/` |
| CSS | `public/assets/dashboard.css` → copié dans `public_html/gestion/assets/` |
| Cron | `*/15 * * * * php artisan social:synchroniser` (PHP `/opt/cpanel/ea-php85`) |

Le `.env` de production reste sur le serveur ; `.env.example` en liste les clés.
