# mmidate

Outil de sondage type Doodle/Framadate, écrit en PHP procédural sur SQLite, **sans dépendance Composer ni framework**. Pensé pour les besoins concrets d'une petite équipe (10–50 participants, quelques dizaines de créneaux par sondage), avec attention particulière à la **gestion d'astreintes** (sélection principal·e + suppléant·e par créneau, remplissage automatique, notifications par email avec confirmation).

URL en prod : <https://polls.iprospective.fr>

## Fonctionnalités

### Pour les participants
- Lien public par UUID partageable
- Connexion par **magic-link** envoyé par email (pas de mot de passe)
- Saisie / modification / suppression de ses disponibilités (Oui / Peut-être / Non) sur chaque créneau
- Champ téléphone + sélection multiple de plateformes de contact (Telegram, Signal, WhatsApp, SMS)
- Vue « Mes astreintes » dès que des assignations ont été posées
- Page de confirmation/contestation des astreintes au clic du lien magique d'astreinte

### Pour les managers (rôle intermédiaire)
- Inscription publique avec validation par l'admin global
- Connexion par mot de passe **ou** magic-link
- Création/édition de leurs propres sondages
- Plusieurs managers possibles sur un même sondage (créateur + invités)
- Invitation d'autres managers actifs sur leurs sondages

### Pour l'admin global
- Identifiants en config (un seul admin global)
- Vue de tous les sondages (avec badge créateur)
- Validation/rejet/suppression des comptes manager (notifications email)
- Assignation de n'importe quel sondage à un ou plusieurs managers
- Accès complet à tous les sondages

### Récap des votes
- Grille sticky horizontale et verticale (date + créneau + récap restent visibles au scroll)
- En-tête de chaque colonne avec compteurs Oui/Peut-être et P/S
- Coloration par ligne et par jour selon des seuils configurables (vert si N personnes en Oui, jaune si N en Oui+Peut-être, sinon rouge)
- Date verte uniquement si tous les créneaux du jour le sont
- Séparateur visuel entre jours
- Masquage par défaut des dates passées (toggle pour les réafficher)
- Vue alternative en cartes pour mobile (récap par créneau)

### Astreintes
- Sélection manuelle : 1 principal·e + 1 suppléant·e par créneau, parmi les voteur·euse·s en Oui/Peut-être
- Compteurs P/S mis à jour en live à chaque sélection
- Impossible d'avoir la même personne aux deux rôles sur un même créneau
- **Remplissage automatique** : algo round-robin équitable basé sur l'usage % (assignations / capacité) avec 4 tiers (50/75/90 %), priorité de libellé configurable (Nuit > Soirée > Journée par défaut)
- Bouton « Vider toutes les astreintes » pour repartir de zéro

### Notifications
- Envoi par email à tous les participants assignés ou à une personne précise
- Message personnalisé optionnel
- Chaque destinataire reçoit la liste de ses créneaux + un lien unique de confirmation/contestation
- Page de confirmation/contestation accessible par token (pas besoin d'être loggué)
- Email automatique au contact configuré du sondage en cas de signalement de problème
- Statut visible côté admin (envoyé / confirmé / contesté + réponse textuelle)

### Vue Participants
- Tableau récap par personne (Oui / Peut-être / Non / Sans réponse / Principal / Suppléant / Usage %)
- Personnes orphelines (zéro vote) séparées
- Tableau Coordonnées dédié (email, téléphone, ✓/— par plateforme)
- Tri cliquable sur toutes les colonnes
- Actions inline en pictos SVG : voir calendrier, éditer, renvoyer notif
- Vue calendrier individuelle par participant (admin)
- Édition admin de nom / email / téléphone / contact / votes

### Description
- Éditeur WYSIWYG (Quill 2.0) pour la description du sondage
- HTML sanitizé côté serveur (whitelist stricte via DOMDocument)
- Rétro-compatible avec les anciennes descriptions plain-text

## Pile technique

- **PHP 8.0+** procédural, **PDO SQLite**
- **HTML/CSS** statique (pas de build front), Quill 2.0 et Inter / Space Grotesk via CDN
- **SMTP** maison via `fsockopen` (pas de PHPMailer), fallback en log fichier si SMTP indisponible
- **Pas de Composer**, pas de namespace, pas d'autoloader — `require_once` explicites

## Structure du projet

```
mmidate/
├── index.php            # bootstrap + dispatcher (≈50 lignes)
├── routes.php           # tableau [METHOD, regex, controller, handler]
├── config.php(.example) # creds admin, SMTP, app_url, seuils
├── seed.php             # CLI : crée le sondage d'exemple Frama
├── migrate_from_frama.php  # CLI : import participants supplémentaires
├── controllers/
│   ├── home.php
│   ├── admin_auth.php           # admin login/logout
│   ├── admin_polls.php          # CRUD sondages
│   ├── admin_dates.php          # dates et créneaux
│   ├── admin_participants.php   # gestion participants
│   ├── admin_assignments.php    # astreintes manuelles + auto-fill
│   ├── admin_notifications.php  # envoi notif + email contact
│   ├── admin_settings.php       # paramètres du sondage
│   ├── admin_managers.php       # validation comptes manager
│   ├── manager_auth.php         # register/login/logout manager
│   ├── manager_dashboard.php    # dashboard manager
│   ├── poll_managers.php        # ajout/retrait manager d'un sondage
│   ├── poll_public.php          # vue publique + magic-link
│   ├── poll_votes.php           # /me du participant
│   └── poll_confirm.php         # confirmation par token
├── services/
│   ├── polls.php                # find_poll, poll_structure
│   ├── participants.php
│   ├── votes.php
│   ├── assignments.php
│   ├── notifications.php        # send_notification_email, issue_notification
│   ├── auto_fill.php            # algo round-robin tier-based
│   ├── managers.php             # CRUD comptes manager + magic-link
│   └── poll_managers.php        # M2M sondage ↔ manager
├── lib/
│   ├── db.php                   # PDO + migrations idempotentes
│   ├── auth.php                 # sessions admin/manager/participant
│   ├── helpers.php              # csrf, render, escape, fmt_day, contact_methods
│   ├── mailer.php               # SMTP maison + fallback log
│   └── html_sanitize.php        # whitelist HTML via DOMDocument
├── public/
│   ├── style.css                # tout le CSS
│   ├── editor.js                # init Quill
│   ├── assignments.js           # compteurs live P/S
│   ├── sortable-table.js        # tri cliquable
│   ├── logo.png                 # logo iProspective
│   └── bg/                      # photos détourées (lynx, chamois, vautour, violon)
├── templates/
│   ├── layout.php · home.php · error.php
│   ├── admin/                   # vues admin
│   ├── manager/list.php         # dashboard manager
│   ├── auth/                    # register, login manager
│   └── poll/                    # vues publiques participant
├── data/                        # gitignored : SQLite + mail.log
├── .htaccess                    # réécriture Apache + blocage fichiers sensibles
└── routes.php
```

## Installation

### Prérequis
- PHP 8.0 ou plus, avec `ext-pdo_sqlite` et `ext-dom`
- Serveur web : Apache (avec `mod_rewrite` et `AllowOverride All`) ou nginx, ou le serveur intégré PHP

### Mise en route

```bash
git clone gitlab:iprospective/tools/mmi-polls.git mmidate
cd mmidate

# Config
cp config.php.example config.php
# Édite config.php : app_url, admin (username/password/email), SMTP, etc.

# Data directory
mkdir -p data
chmod u+rw data

# Lance le serveur (dev)
php -S 127.0.0.1:8000 index.php
# Ou déploie sous Apache (.htaccess fourni)
```

Au premier hit, `db_migrate()` crée toutes les tables et applique les `ALTER TABLE` idempotents pour les colonnes ajoutées au fil du temps.

### Seed du sondage d'exemple

```bash
php seed.php
# UUID figé : b8e3a7f1-2d94-4c6a-9e5b-3f1a2c8d7e60
# → https://votre-url/p/b8e3a7f1-2d94-4c6a-9e5b-3f1a2c8d7e60
```

Pour importer 4 participants supplémentaires depuis le Frama original :

```bash
php migrate_from_frama.php [uuid]
```

## Configuration

`config.php` :

```php
return [
    'app_url' => 'https://votre-domaine.example',
    'admin' => [
        'username' => 'admin',
        'password' => '...',         // À CHANGER en prod
        'email'    => '',            // Notif inscription manager
    ],
    'db_path' => __DIR__ . '/data/app.sqlite',
    'smtp' => [
        'host'       => 'smtp.exemple.com',  // vide = fallback log
        'port'       => 587,
        'encryption' => 'tls',       // 'tls', 'ssl', ''
        'username'   => '',
        'password'   => '',
        'from'       => 'no-reply@exemple.com',
        'from_name'  => 'mmidate',
    ],
    'magic_link_ttl' => 3600,
    'highlight' => [
        'yes_min'      => 2,         // seuil vert
        'yesmaybe_min' => 2,         // seuil jaune
    ],
    'auto_fill' => [
        'priority' => ['Nuit', 'Soirée', 'Journée'],
    ],
];
```

## Rôles

| Rôle | Authentification | Périmètre |
|---|---|---|
| **Admin global** | username + password (config) | Tout (sondages + managers) |
| **Manager** | mot de passe ou magic-link | Sondages dont il est dans `poll_managers` |
| **Participant** | magic-link (par sondage) | Saisie/édition de ses propres réponses |

## Déploiement Apache

Le `.htaccess` fourni fait la réécriture vers `index.php`, bloque l'accès direct à `data/`, `lib/`, `templates/`, `controllers/`, `services/`, `routes.php`, fichiers `*.sqlite`, etc.

Pré-requis serveur :
- `mod_rewrite` activé
- `AllowOverride All` dans le VHost
- Droits d'écriture sur `data/` pour l'utilisateur du serveur web

## Limites connues

- Pas de tests automatisés (couverture par revue manuelle)
- Pas d'API REST (interface HTML uniquement)
- SMTP maison : OK pour la plupart des serveurs courants, à remplacer par PHPMailer si fournisseur exotique
- Pas de pagination ni recherche sur les listes (overkill à cette échelle)
- Internationalisation : français uniquement

## Licence

Usage interne iProspective. Pas de licence publique pour l'instant.
