# mmidate

Outil de sondage type Doodle/Framadate, écrit en PHP procédural sur SQLite, **sans dépendance Composer ni framework**. Pensé pour les besoins concrets d'une petite équipe (10–50 participants, quelques dizaines de créneaux par sondage), avec attention particulière à la **gestion d'astreintes** (sélection principal·e + suppléant·e par créneau, remplissage automatique, notifications par email avec confirmation).

URL en prod : <https://polls.iprospective.fr>

## Fonctionnalités

### Pour les participants
- Lien public par UUID partageable
- Connexion par **magic-link** envoyé par email (pas de mot de passe)
- Saisie / modification / suppression de ses disponibilités (Oui / Peut-être / Non) sur chaque créneau
- Champ téléphone + sélection multiple de plateformes de contact (Telegram, Signal, WhatsApp, SMS)
- Champ adresse (géocodée) — pour la prise en compte de la distance dans l'algo et l'affichage sur la carte
- Vue « Mes astreintes » dès que des assignations ont été posées
- **Demande de remplacement** : bouton 🔄 par astreinte qui propose à un·e candidat·e (yes/maybe sur ce créneau) de reprendre le slot par email ; premier·ère qui clique gagne
- Vue calendrier perso + carte perso (trajet chez-soi → départ → arrivée → chez-soi avec distance totale)
- Abonnement iCal personnel à ses astreintes (Google Calendar, Apple, Thunderbird…)
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
- **Remplissage automatique** : algo round-robin équitable basé sur l'usage % (assignations / capacité) avec 4 tiers (50/75/90 %), priorité de libellé configurable (Nuit > Soirée > Journée par défaut). Tiebreaker GPS : privilégie le·la plus proche du point de départ si la gestion d'adresses est active.
- **Drag'n'drop sur le calendrier** pour déplacer une astreinte ou en échanger deux. Modal de confirmation, demande de validation envoyée à la·aux personne·s concernée·s par email, application atomique uniquement si toutes acceptent. Bloque les déplacements vers un créneau où la personne a voté « non ».
- **Demandes de remplacement** initiées par le·la participant·e elle·lui-même depuis sa page perso (`/me`) — voir section participants.
- Bouton « Vider toutes les astreintes » pour repartir de zéro
- Horaires précis paramétrables par libellé de créneau (Journée/Soirée/Nuit avec chevauchement intentionnel)

### Notifications
- Envoi par email à tous les participants assignés ou à une personne précise
- Message personnalisé optionnel (avec message par défaut configurable dans `CONFIG.notifications.default_message`)
- Email de test pour vérifier le rendu avant l'envoi réel
- Chaque destinataire reçoit la liste de ses créneaux + un lien unique de confirmation/contestation
- Page de confirmation/contestation accessible par token (pas besoin d'être loggué)
- Email automatique au contact configuré du sondage en cas de signalement de problème
- Statut visible côté admin (envoyé / confirmé / contesté + réponse textuelle)
- **Override manager** : peut confirmer/contester/réinitialiser hors-mail pour les personnes qu'on a eu en direct ou sans internet (notif synthétique avec `responded_by='manager'`)
- **Indicateur « À renotifier »** quand les astreintes ont changé après l'envoi du dernier email (badge ⚠️ côté manager)

### Carte (géolocalisation)
- Adresse de départ / d'arrivée du sondage géocodées (multi-backend : Photon puis Nominatim)
- Adresse par participant·e géocodée → distance prise en compte dans l'auto-fill
- **Vue carte admin** (`/admin/.../map`) : tous les pins + tous les trajets routiers (chez → départ → arrivée → chez) via OSRM avec cache. Tableau récap trié par distance totale, filtre par participant·e, click pour highlight.
- **Vue carte perso** (`/p/UUID/me/map`) : trajet personnel + récap par leg
- Labels permanents (prénom) zoomables, décalés en rosace si plusieurs adresses superposées
- **Focus participant·e** (la « maman » dans le cas d'usage transport) : marker plus large, icône dédiée, trajet toujours visible

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
- **HTML/CSS** statique (pas de build front), Quill 2.0, Leaflet 1.9.4 + tuiles OpenStreetMap, Inter / Space Grotesk via CDN
- **SMTP** maison via `fsockopen` (pas de PHPMailer), fallback en log fichier si SMTP indisponible
- **Géocodage** : Photon (komoot) en priorité, Nominatim en fallback, cache local (TTL différenciés hits/miss)
- **Routing** : OSRM public (`router.project-osrm.org`) avec cache local, fallback Haversine si OSRM down
- **Pas de Composer**, pas de namespace, pas d'autoloader — `require_once` explicites
- **Logger paramétrable** (4 niveaux) avec notification email à l'admin sur exception non catchée + anti-spam horaire

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
│   ├── admin_polls.php          # CRUD sondages + géocodage start/end
│   ├── admin_dates.php          # dates, créneaux, horaires précis
│   ├── admin_participants.php   # gestion participants + toggles AJAX
│   ├── admin_assignments.php    # astreintes manuelles + auto-fill + bump stale
│   ├── admin_notifications.php  # envoi notif + email contact + override manager
│   ├── admin_settings.php       # paramètres du sondage
│   ├── admin_managers.php       # validation comptes manager
│   ├── admin_calendar.php       # vue calendrier mensuel
│   ├── admin_activity.php       # log d'activité par sondage
│   ├── admin_map.php            # carte avec trajets + recompute
│   ├── manager_auth.php         # register/login/logout manager
│   ├── manager_dashboard.php    # dashboard manager
│   ├── poll_managers.php        # ajout/retrait manager d'un sondage
│   ├── poll_public.php          # vue publique + magic-link
│   ├── poll_votes.php           # /me du participant + carte + calendrier
│   ├── poll_confirm.php         # confirmation par token (email)
│   ├── swaps.php                # demandes de remplacement entre participant·e·s
│   ├── moves.php                # drag'n'drop calendrier (manager → validation)
│   └── ical.php                 # flux iCal personnel par token
├── services/
│   ├── polls.php                # find_poll, poll_structure
│   ├── participants.php
│   ├── votes.php
│   ├── assignments.php          # + mark_participants_assignments_stale
│   ├── notifications.php        # send + poll_confirmation_status + override
│   ├── auto_fill.php            # algo round-robin tier-based
│   ├── managers.php             # CRUD comptes manager + magic-link
│   ├── poll_managers.php        # M2M sondage ↔ manager
│   ├── activity_log.php         # log_activity
│   ├── geocoder.php             # multi-backend Photon/Nominatim + cache
│   ├── routing.php              # OSRM + fallback Haversine + cache
│   ├── ical.php                 # flux iCal RFC 5545
│   ├── swaps.php                # demandes de remplacement + tokens + emails
│   └── move_requests.php        # déplacement/échange initié manager
├── lib/
│   ├── db.php                   # PDO + migrations idempotentes
│   ├── auth.php                 # sessions admin/manager/participant
│   ├── helpers.php              # csrf, render, escape, fmt_day, contact_methods, poll_focus_*
│   ├── mailer.php               # SMTP maison + fallback log
│   ├── logger.php               # logger paramétrable + notify_admin_error
│   └── html_sanitize.php        # whitelist HTML via DOMDocument
├── public/
│   ├── style.css                # tout le CSS
│   ├── editor.js                # init Quill
│   ├── assignments.js           # compteurs live P/S
│   ├── sortable-table.js        # tri cliquable
│   ├── contact-toggles.js       # toggles AJAX plateformes de contact
│   ├── map-render.js            # markers Leaflet + polylines + highlight
│   ├── calendar-dnd.js          # drag'n'drop des astreintes + modal
│   ├── focus-pin.png            # (optionnel) icône custom pour le focus participant
│   ├── logo.png                 # logo iProspective
│   └── bg/                      # photos détourées (lynx, chamois, vautour, violon)
├── templates/
│   ├── layout.php · home.php · error.php
│   ├── _month_calendar.php      # partial grille mensuelle (avec mode dnd)
│   ├── _slot_legend.php         # partial légende horaires
│   ├── admin/                   # vues admin
│   ├── manager/list.php         # dashboard manager
│   ├── auth/                    # register, login manager
│   ├── poll/                    # vues publiques participant
│   ├── swap/                    # composition + landing demande de remplacement
│   └── move/                    # landing demande de déplacement (drag'n'drop)
├── data/                        # gitignored : SQLite + mail.log + geocode.log + routing.log
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
        'email'    => 'vous@ex.com', // Notif inscription manager (⚠️ vide = pas de notif, juste trace dans mail.log)
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
    'addresses' => [
        'enabled' => true,                              // master switch global
    ],
    'geocoder' => [
        'backends' => ['photon', 'nominatim'],          // ordre d'essai
    ],
    'routing' => [
        'backends' => ['osrm', 'haversine'],            // OSRM puis fallback
        'osrm_url' => 'https://router.project-osrm.org',// self-host conseillé en prod
    ],
    'notifications' => [
        'default_message' => '',                        // pré-rempli dans le formulaire d'envoi
    ],
    'log' => [
        'level' => 'warn',                              // error|warn|info|debug
        'path'  => __DIR__ . '/data/app.log',
        'email_errors' => true,                         // envoie les exceptions à admin.email (anti-spam horaire)
    ],
    'asset_version' => 1,                                // bumper à chaque modif de CSS/JS
];
```

## Rôles

| Rôle | Authentification | Périmètre | URL de connexion |
|---|---|---|---|
| **Admin global** | username + password (config) | Tout (sondages + managers) | `/login` (section dépliable) |
| **Manager** | mot de passe ou magic-link | Sondages dont il est dans `poll_managers` | `/login` |
| **Participant** | magic-link (par sondage) | Saisie/édition de ses propres réponses | `/p/<uuid>/login` (lien partagé) |

La page `/login` est unifiée admin + manager pour éviter la confusion à l'arrivée. Les participant·e·s d'un sondage utilisent le lien du sondage qui leur a été partagé.

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

**GNU Affero General Public License v3.0 (AGPL-3.0)**.

Copyright © 2026 iProspective.

Vous êtes libre d'utiliser, modifier et redistribuer ce logiciel sous les termes
de l'AGPL-3.0. Voir [LICENSE](LICENSE) pour le texte complet.

Particularité de l'AGPL : si vous modifiez mmidate et le **déployez comme service en
ligne** (même sans en distribuer le code source), vous devez rendre vos modifications
accessibles aux utilisateur·rice·s du service. C'est la version « SaaS-safe » de la GPL.

Ce logiciel est fourni « tel quel », sans garantie d'aucune sorte.
