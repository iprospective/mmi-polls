# Changelog

Historique des évolutions de mmidate, dans l'ordre chronologique. Format inspiré de [Keep a Changelog](https://keepachangelog.com/).

## 2026-06-03 — Connexion unifiée + toggle email d'erreur + page 500 propre

### Changed
- **Page de connexion unifiée à `/login`.** Les utilisateur·rice·s lambda cliquaient « Connexion » en haut à droite en pensant remplir le sondage, alors que c'était la connexion manager. Le formulaire admin est maintenant aussi sur `/login` (dans un `<details>` repliable « Connexion administrateur·rice global·e »), avec un encart d'avertissement clair pour les participant·e·s : « Vous voulez répondre à un sondage ? Utilisez le lien du sondage de la forme `/p/<id>` ». La route `/admin/login` continue de marcher (rétrocompatibilité) et rend le même formulaire unifié. `templates/admin/login.php` supprimé (dead code).
- **Lien "Admin" du topbar retiré** : la connexion admin se fait depuis `/login`, plus besoin d'un raccourci à part.

### Added
- **`CONFIG.log.email_errors`** (bool, défaut `true`) : toggle global pour activer/désactiver l'envoi par email des exceptions catchées. Pratique en dev / pendant les migrations.
- **`notify_admin_error` partout** : les catches métier (`create_move_request`, `issue_swap_request`, auto-fill PK clash) sont désormais split en `RuntimeException | InvalidArgumentException` (erreur attendue → `log_warn`, pas de notif admin) vs `Throwable` (erreur inattendue → `notify_admin_error` + 500). L'utilisateur·rice voit toujours un message clair, l'admin reçoit un email seulement quand quelque chose d'inhabituel se produit.
- **Page 500 propre** : le handler global d'exception utilise maintenant `templates/error.php` avec un message digne (« L'administrateur·rice a été averti·e par email. Réessayez. »), avec fallback HTML minimal si le rendu lui-même est cassé.

## 2026-06-03 — Logger paramétrable + notif admin sur exceptions

### Added
- **`lib/logger.php`** : logger paramétrable avec 4 niveaux (`error` < `warn` < `info` < `debug`). Configuration via `CONFIG.log.level` et `CONFIG.log.path` (défaut `data/app.log`). Format une ligne ISO date + level + message + context JSON. API `log_error/warn/info/debug()` + `log_throwable($e)` pour aplatir un Throwable.
- **`notify_admin_error(Throwable, $context_msg, $data)`** : envoie l'exception par email à `CONFIG.admin.email` (en plus du log). Anti-spam horaire par hash `(classe, message, contexte)` pour ne pas inonder la boîte si une erreur revient en boucle. N'utilise pas `send_mail()` pour éviter la récursion en cas de panne SMTP.
- **Handler global d'exceptions non catchées** dans `index.php` : toute exception PHP qui remonte jusqu'au front controller est loggée + envoyée à l'admin, puis une page d'erreur sobre est affichée. Plus de silences inexplicables.
- **Logs auto dans `send_mail()`** : `INFO mail sent` au succès, `ERROR SMTP send failed` à l'échec (avec host, port, encryption, fichier) + appel à `notify_admin_error()` + conservation du mail dans `mail.log` pour rejouer.

## 2026-06-03 — Fix inscription manager : email de confirmation muet

### Fixed
- **Inscription manager : silence radio.** À l'inscription via `/register`, aucun email n'était envoyé à l'inscrit·e (le flash UI promettait pourtant « Vous recevrez un email de confirmation »). En plus, la notif à l'admin global n'était envoyée que si `CONFIG.admin.email` était renseigné — sans warning si la conf manquait. Résultat en prod : on remplit le formulaire, rien ne part, on attend en vain.
  - Le·la nouveau·elle inscrit·e reçoit désormais un email « compte en attente de validation, on vous prévient » immédiatement, indépendant de la config admin.
  - Si `admin.email` n'est pas configuré, la notif perdue est tracée dans `data/mail.log` sous `[admin.email missing — notif perdue]` pour que le sysop voie le problème.
  - `config.php.example` documente clairement l'importance de cette valeur.

## 2026-06-03 — Carte : trajets, filtre, highlight, focus participant·e

### Added
- **Trajets routiers complets sur la carte.** Nouveau service `services/routing.php` multi-backend (OSRM en priorité via `router.project-osrm.org`, Haversine en fallback). Pour chaque participant·e géolocalisé·e, calcul des 3 legs chez-soi → départ → arrivée → chez-soi avec géométrie GeoJSON complète. Cache global `route_cache` (clé = hash des endpoints arrondis à 5 décimales, dédoublonné entre participant·e·s / sondages), TTL 30j pour hits, 5min pour miss.
- **Carte admin enrichie.** Polylines par participant·e (couleur dédiée, pointillé pour le leg start→end partagé), tableau récap trié par distance totale croissante (le plus proche d'abord), tooltips au survol des lignes (distance + durée + leg). Bouton « Recalculer trajets » qui purge le cache pour forcer un re-fetch OSRM.
- **Filtre par participant·e sur la carte** (dropdown au-dessus). Affiche uniquement le pin et le trajet de la personne sélectionnée ; start/end et focus restent visibles.
- **Highlight au clic.** Cliquer un pin OU une ligne du tableau met en avant le·la participant·e : son trajet est épaissi et opaque, les autres trajets et pins sont dimés, sa ligne de tableau est surlignée. Re-cliquer ou cliquer le fond de carte = reset.
- **Carte personnelle `/p/UUID/me/map`.** Le·la participant·e voit son propre trajet avec carte récap (total + détail par leg avec durée). Indicateur `⚠️ vol d'oiseau` si OSRM était down et qu'on a fallback Haversine.
- **Labels permanents zoomables.** Tooltip permanent (prénom) sous chaque pin, halo blanc pour la lisibilité sur tuiles. Sizing au zoom via classes `mm-zoom-far|mid|near|close` posées sur `#map` au `zoomend` de Leaflet — petit en zoom large, gros en proche.
- **Labels décalés en rosace** quand les coords sont identiques (ou identiques à 5 décimales près). 8 positions radiales autour du pin pour ne plus superposer les prénoms.
- **Focus participant·e** : nouveau champ `polls.focus_participant_id` configurable dans Paramètres du sondage (dropdown parmi les participant·e·s). Cette personne a un marker plus large avec emoji 🤰 (ou image perso via `public/focus-pin.png`), son trajet est sur une layer dédiée **toujours visible** (override du toggle). Pratique pour garder le contexte autour de la personne autour de qui tourne l'organisation (ex. maman dont on assure le transport).

## 2026-06-03 — Drag'n'drop calendrier + manager override notifications

### Added
- **Drag'n'drop d'astreintes sur le calendrier admin.** Glisser une assignation depuis une case vers une autre : drop sur case vide = déplacement (demande à 1 personne), drop sur case occupée = échange (demande à 2 personnes). Modal de confirmation avec récap textuel + textarea pour un message libre. Drop sur soi-même ou sur le même slot dans l'autre rôle déjà tenu = bloqué côté UI. Drop refusé serveur si la personne n'a pas voté yes/maybe sur le créneau cible (évite de demander quelque chose que la personne a déclaré indisponible).
  - Schéma : `move_requests` + `move_request_responses` (token par personne).
  - Application atomique uniquement quand TOUTES les personnes concernées ont accepté. Re-vérification des propriétaires courants au moment d'appliquer pour détecter une divergence (status `expired`).
  - Emails pour chaque étape (invite, applied, declined, cancelled, expired). Récap au `contact_email` du sondage si configuré.
  - Section « Demandes en cours » sous le calendrier avec compteurs et bouton annuler.
  - Bump `assignments_updated_at` des participant·e·s impacté·e·s → badge « À renotifier » apparaît automatiquement.
- **Manager peut confirmer/contester hors-mail.** Sur `/admin/.../assignments`, la section « État des envois » devient un statut par personne assignée (avec OU sans notif envoyée). Trois actions par ligne : ✓ Confirmer / ✗ Signaler (prompt JS pour une note) / ↺ Réinitialiser. Pour les personnes sans notif (cas « pas d'internet »), création d'une notif synthétique avec un token bidon et `responded_by='manager'`. Activity log à chaque override. Nouveau champ `notifications.responded_by`.

### Changed
- **Adresse dans le récap coordonnées** des participant·e·s admin (colonne conditionnée à `poll_addresses_enabled`). Affiche l'adresse géocodée + lat/lng si localisée, sinon l'adresse brute + ⚠️ non-géolocalisée.

### Fixed
- **Tableau récap trajets : colonnes désalignées.** Refonte avec `<colgroup>` + largeurs fixes + 3 colonnes dédiées pour les legs au lieu d'une seule colonne « Détail » fourre-tout. Ajout d'attributs `data-l` pour le mode responsive mobile.

## 2026-06-02 — Demandes de remplacement + indicateur « à renotifier »

### Added
- **Demandes de remplacement d'astreinte entre participant·e·s.** Sur sa page `/me`, un lien 🔄 par astreinte ouvre un formulaire qui pré-coche les candidat·e·s (yes/maybe sur ce créneau). Chaque destinataire reçoit un email avec un token unique vers `/swap/TOKEN`. Le·la premier·ère qui clique « J'accepte » déclenche un swap atomique de l'assignation.
  - Schéma : `swap_requests` + `swap_request_targets`.
  - Atomicité : transaction avec `UPDATE … WHERE status='open'` + `rowCount()` pour battre la concurrence. Si le manager a réassigné entretemps, status `expired`.
  - Si l'accepteur tenait l'autre rôle du même créneau, il est libéré (un·e participant·e ne peut pas être à la fois P et S sur le même slot).
  - 4 emails : invite, requester (accepté), accepteur (confirmation, mention si autre rôle libéré), too-late aux autres targets, annulation.
  - Vue manager : section « Demandes en cours » sur `/admin/.../assignments` avec compteurs (sollicité·e·s / déclinés / en attente) et bouton annuler.
- **Indicateur « À renotifier ».** Nouveau champ `participants.assignments_updated_at` bumpé à chaque save manuel, auto-fill ou clear d'astreintes (sur les pids effectivement impactés via diff before/after). Badge ⚠️ côté manager dans la liste des participants et dans le tableau des envois quand `assignments_updated_at > notification.sent_at` (les astreintes ont changé depuis l'envoi du dernier email).

## 2026-05-31 — Multi-manager, fix mobile définitif

### Fixed
- **Mobile : tableaux participants enfin lisibles.** Une règle `.grid-wrap { display: none }` du mode mobile, censée masquer le wrapper de la grille de votes récap, frappait en réalité **tous** les `.grid-wrap` — y compris celui des tableaux participants. Remplacé par `.vote-grid:not(.assignments-grid) { display: none }` ciblant directement la table récap, et bascule des tableaux participants en **scroll horizontal** (approche éprouvée, abandon de la transformation tables → cartes qui était fragile).
- **Mobile : colonnes email/tél redondantes** dans le tableau stats principal. La règle responsive de réaffichage s'appliquait aux deux tables `.participants-table` ; restreinte à `.contacts-table` uniquement. La table stats hérite désormais du masquage desktop, et la table Coordonnées (juste en dessous) reste seule source de cette info.

### Added
- **Système de comptes manager** (rôle intermédiaire entre admin global et participant).
  - Tables `managers` et `manager_magic_links`, colonne `polls.manager_id`
  - Inscription publique `/register` avec validation par l'admin
  - Connexion par mot de passe (`password_hash` + `password_verify`) **ou** magic-link
  - Dashboard `/manager` listant les sondages dont la personne est manager
  - Page `/admin/managers` : sections en attente / actifs / refusés
  - Cloisonnement : `require_poll_access($poll)` remplace `require_admin()` sur toutes les routes par-sondage
  - Emails automatiques : notif admin à l'inscription, notif manager à la validation/refus
  - Topbar adaptée par rôle
- **Multi-manager** : un sondage peut avoir plusieurs managers.
  - Nouvelle table `poll_managers(poll_id, manager_id, added_at, added_by_admin, invited_by)`
  - Backfill idempotent depuis `polls.manager_id` au boot
  - Section « Managers » dans les Paramètres du sondage : liste, ajout par email, retrait
  - Pour l'admin : dropdown supplémentaire piochant dans tous les managers actifs absents du sondage
  - Email auto au manager ajouté à un sondage
  - Garde-fou : un manager seul ne peut pas se retirer (sinon le sondage devient orphelin)

## 2026-05-31 — Refonte architecturale + paiement et téléphone

### Changed
- **Refonte : éclate `index.php` en routes/controllers/services.** index.php passe de 1178 lignes à 48 (bootstrap + dispatch). Création de `routes.php` (tableau de routes), 11 fichiers `controllers/`, 7 fichiers `services/`. Aucun changement fonctionnel, juste une réorganisation. Approche procédurale, pas de namespace ni autoloader. `.htaccess` mis à jour pour bloquer les nouveaux dossiers.

### Added
- **Champ téléphone** et **moyens de contact préférés** par participant (Telegram, Signal, WhatsApp, SMS). Stockage en CSV triée (`signal,telegram`) → permet la multi-sélection ultérieurement **sans migration DB**.
- **2e tableau « Coordonnées »** dans la vue Participants : email + tél cliquables (mailto:, tel:) + colonne ✓/— par plateforme avec fond coloré.
- **Multi-sélection des plateformes de contact** : `<select>` remplacé par cases à cocher en ligne, helper centralisé `contact_methods_pills($csv)` pour le rendu.

### Changed
- **Email de contact du sondage déplacé** : du bloc Notifications de l'onglet Astreintes vers l'onglet Paramètres. Sur Astreintes : rappel discret avec lien vers Paramètres.

### Fixed
- **Responsive participants v1** : `.grid-wrap` perd ses contraintes (`max-height`, `overflow`, fond, bordure) sur mobile. Label de colonne `data-l` affiché en préfixe de toutes les cellules sur mobile (sauf 1re).
- **Mobile (sous-régression)** : la règle `.contacts-table { display: table-cell }` n'était pas bornée à desktop ; spécificité égale à la règle responsive, l'ordre source la faisait gagner. Wrappée dans `@media (min-width: 701px)`.

## 2026-05-31 — UX vues et grille

### Added
- **Vue participants enrichie** :
  - Tri cliquable sur toutes les colonnes (JS dans `public/sortable-table.js`)
  - Nouvelle colonne « Maj votes » (durée relative depuis dernière modif, tooltip avec timestamp exact)
  - Actions par ligne : voir calendrier, éditer, renvoyer notif (icônes SVG inline 30×30, hover bleu)
  - Section dédiée « Personnes orphelines » (zéro vote) en grisé, mêmes colonnes pour tri local
  - Email en tooltip natif sur le nom (curseur `help`), colonne Email cachée desktop
- **Calendrier admin d'un participant** : page `/admin/polls/{uuid}/participants/{id}/calendar` avec stats strip + liste des astreintes par jour + actions.
- **Date dernière modif** des votes : colonne `participants.votes_updated_at` mise à jour à chaque save côté participant et admin.
- **Mise en avant des astreintes sur la grille récap** : sur la cellule de vote de chaque personne d'astreinte, ring bleu (principal) ou indigo rayé (suppléant) + badge **P** / **S** en coin haut-droit. Légende discrète au-dessus de la grille.
- **Compteurs par participant dans l'en-tête de la grille** : sous le prénom tronqué, deux lignes (`✓ N · ? N` puis `P N · S N`).
- **Nav admin par onglets** : partial `_admin_nav.php` partagé entre toutes les sous-pages d'un sondage.
- **Pages admin séparées** :
  - `/admin/polls/{uuid}` n'affiche plus que la grille de réponses
  - `/admin/polls/{uuid}/dates` : gestion dates & créneaux (extraite)
  - `/admin/polls/{uuid}/settings` : titre, description (WYSIWYG), zone dangereuse (extraite)
- **Masquage des dates passées** par défaut (toggle `?show_past=1`) sur grille récap et page astreintes admin.
- **Sticky footer** sur le tableau participants (totaux toujours visibles au scroll vertical).

### Fixed
- **Confirm() manquant** sur le formulaire d'envoi de notifications du bloc admin Astreintes.

## 2026-05-31 — Astreintes : algo auto-fill v3

### Fixed
- **Bug majeur de l'auto-fill** : la closure `$pick_for` était définie hors de la boucle d'attribution et capturait `$counts` / `$last_ts` par **valeur**, donc snapshot initial (tout à zéro) figé. Le tri restait statique sur l'état initial, et le tiebreak « low credits ASC » du tier 0 picksait toujours la même personne (Marie 28 crédits) avant les autres (Aude 46 crédits). **Refactor** : closure usort créée dans la boucle → snapshot frais à chaque pick.
- **Algo v3** : tri primaire passe de `count ASC` à `usage_pct ASC` (= count / capacité). Quelqu'un avec plus de crédits Oui est désormais pické proportionnellement plus souvent.

## 2026-05-31 — Astreintes : auto-fill v2 + vue stats

### Added
- **Vue stats participants** (`/admin/polls/{uuid}/participants`) : tableau par personne avec #Oui / #Peut-être / #Non / #Sans réponse / #Principal·e / #Suppléant·e / Total / Usage % colorée par tier / Statut notif. Ligne de totaux + couvertures globales.
- **Bouton « Vider toutes les astreintes »** pour repartir de zéro avant un auto-fill.

### Changed
- **Auto-fill v2** : tous les principaux remplis AVANT le moindre suppléant. Algorithme à 4 tiers d'usage (< 50 % prioritaire, 50–75 %, 75–90 %, ≥ 90 % dernier recours). Capacité = crédits Oui + ½ Peut-être. Au sein du tier 0, low yes_credits passe en premier (« boost Cécile » pour les personnes qui ont peu coché Oui).
- **Refonte mobile responsive** : grille récap → cartes par créneau avec `<details>` repliable pour la liste des voteurs. Page astreintes → cartes via `display: block` pur CSS.

## 2026-05-31 — Astreintes : notifications + auto-fill v1

### Added
- **Outil de remplissage automatique** (`POST /admin/polls/{uuid}/assignments/auto-fill`) : round-robin par priorité de libellé (Nuit > Soirée > Journée configurable). Bouton avec confirm dans l'admin Astreintes.
- **Notifications d'astreintes** :
  - Schema : nouvelles tables `notifications` et colonne `polls.contact_email`
  - Envoi par email à tous les assignés ou à une personne précise
  - Message personnalisé optionnel
  - Token unique par destinataire avec lien `/p/{uuid}/confirm?token=...`
  - Page de confirmation : bouton « Je confirme » + `<details>` « Je signale un problème » avec textarea
  - Signalement déclenche un email vers `polls.contact_email`
  - Statut tracké en base (sent / confirmed / contested + réponse)
  - Tableau de statut côté admin
- **Section « Mes astreintes »** sur `/p/{uuid}/me` du participant.

## 2026-05-31 — Astreintes : sélection manuelle

### Added
- **Outil d'astreintes admin** (`/admin/polls/{uuid}/assignments`) : sélection d'1 personne principale + 1 suppléante par créneau, parmi les voteur·euse·s en Oui/Peut-être (en `<optgroup>` distincts). Compteurs P/S mis à jour en live côté JS à chaque sélection.
- **Anti-doublon** : une personne ne peut pas être à la fois principale et suppléante sur le même créneau. Option désactivée dans le `<select>` de l'autre rôle, libellé « — déjà sur l'autre rôle ». Sélection conflictuelle libère automatiquement l'autre rôle.

## 2026-05-30 — UX grille récap + données réelles

### Added
- **Sticky horizontal/vertical** de la grille : colonnes Date / Créneau / Récap figées à gauche/droite, en-tête figée en haut, hauteur bornée pour permettre le scroll interne.
- **Prénoms participants tronqués à 3 caractères** + tooltip natif au survol pour le nom complet (colonne ~42px au lieu de 110+).
- **Séparateur entre les jours** dans la grille (`border-top: 2px` sur `.day-first`).
- **Statut Date agrégé** : la cellule date n'est verte que si **tous** ses créneaux sont verts (règle du pire statut : bad > warn > ok).
- **Script de migration des participants Frama** : `migrate_from_frama.php` ajoute Aude, Alice, Marie, Cécile avec leurs votes verbatim (idempotent, skip si email déjà présent).

## 2026-05-27 — Polish visuel

### Added
- **WYSIWYG (Quill 2.0)** pour la description du sondage, avec sanitization HTML serveur via DOMDocument (whitelist `p, br, strong, em, u, s, ul, ol, li, a, h1–h6, blockquote, pre, code, hr, span`). Helpers `render_description()` rétro-compatible avec les anciennes descriptions plain-text. Chargement conditionnel via flag `include_editor`.
- **Trame de fond avec photos détourées** (lynx, chamois, vautour, violon) via rembg (U²-Net), positionnées en `background-image: fixed` aux 4 coins, opacité 18%, filtres sépia léger.

### Changed
- **Wordmark MMI en serif** + trame de fond (première version SVG).

## 2026-05-26 — Mise en place initiale

### Added
- **Commit initial** : outil de sondage type Doodle/Framadate.
  - Stack : PHP procédural + SQLite, sans dépendance externe
  - Partage par UUID, admin unique en config
  - Magic-link participants (TTL 1h, token sha256)
  - CRUD sondages, dates, créneaux, votes
  - Grille récap avec ✓/?/✗ par participant
  - CSRF, sessions httpOnly+SameSite, requêtes préparées
  - `.htaccess` pour Apache
- **Typographie et présentation améliorées** : Inter via Google Fonts, hiérarchie de titres (h1 1.75rem letter-spacing négatif), cards avec ombre douce, focus ring bleu sur inputs, tabular-nums sur la grille, palette slate.
- **Mise en couleur du récap** : 3 statuts (ok / warn / bad) selon `yes_min` et `yesmaybe_min` configurables.
- **Logo iProspective dans la topbar** + favicon, wordmark `mmi|date` (Space Grotesk 500 + 700, 2 couleurs).
