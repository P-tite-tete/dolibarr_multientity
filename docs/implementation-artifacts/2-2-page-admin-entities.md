# Story 2.2 : Page admin CRUD entités

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a super-administrateur Dolibarr,
I want une page d'administration pour créer, renommer, (dé)activer les entités gérées,
so that je peux configurer les sociétés de l'instance sans toucher à la base de données.

## Acceptance Criteria

1. Page `admin/entities.php` accessible depuis l'onglet d'admin du module (ajouter l'onglet « Entités » dans `multientityAdminPrepareHead()`).
2. Sécurité : `if (!$user->admin) accessforbidden();` (super-admin uniquement, FR2) ; `GETPOST`/`GETPOSTINT` typés ; vérif `newToken()` sur **toute** action POST ; sorties échappées (`dol_escape_htmltag`).
3. **Liste** : tableau des entités via `Multientity::listEntities()` — colonnes entity_id, label, code, pastille couleur, statut actif/inactif, actions.
4. **Création** : formulaire (label requis, code optionnel, couleur optionnelle `#RRGGBB`) → `Multientity::createEntity()`. Message succès/erreur via `setEventMessages()`.
5. **Renommage/édition** : éditer label/code/couleur d'une entité existante → nouvelle méthode `Multientity::updateEntity($entity_id, $label, $code, $color)` (à ajouter au service, mêmes conventions que createEntity : transaction, escape, validation couleur `/^#[0-9A-Fa-f]{6}$/`, label requis). **`entity_id` immuable** (jamais modifié). Le **renommage/recoloriage de l'entité 1 est PERMIS** (seule sa désactivation est interdite). Retour : `1` si OK, `<0` si erreur.
6. **(Dés)activation** : boutons activer/désactiver → `Multientity::setActive()`. L'entité 1 n'affiche **pas** d'action « désactiver » (garde UI) ; si le service refuse, afficher `CannotDeactivateMainEntity`. **Avant tout `setActive()`/`updateEntity()`, la page vérifie l'existence via `getEntity($entity_id)`** (sinon message d'erreur) — évite un UPDATE fantôme (MySQL ne remonte pas d'erreur sur entity_id inexistant, `affected_rows=0`).
7. **Pas de suppression** : aucune action delete (FR2 — désactivation seulement).
8. i18n : libellés via `$langs->trans()` ; ajouter les clés aux **5** fichiers `multientity.lang` : `Entities`, `NewEntity`, `EditEntity`, `EntityLabel`, `EntityCode`, `EntityColor`, `EntityCreated`, `EntityUpdated`, `EntityActivated`, `EntityDisabled`, `CannotDeactivateMainEntity`, `InvalidColorFormat`, `LabelRequired`, `EntityNotFound`, `EntityStatusActive`, `EntityStatusInactive`. (Pour les libellés génériques boutons, réutiliser les clés core via `$langs->trans('Modify'|'Enable'|'Disable'|'Save')` chargées par `loadLangs(array('admin','multientity@multientity'))`.)
9. Compat 19→23+ : `load_fiche_titre`, `dol_get_fiche_head/end`, `getDolGlobalString`, pas de `$_POST`/`$_GET` direct, `header()` suivi de `exit;`.
10. Robustesse : **label vide ET couleur invalide refusés côté page AVANT appel service**, avec message `setEventMessages()` (`LabelRequired` / `InvalidColorFormat` — regex `/^#[0-9A-Fa-f]{6}$/`). Ne pas s'appuyer sur le comportement silencieux du service (qui ignore une couleur invalide sans erreur). Après action POST → redirect (PRG) puis `exit;`.

## Tasks / Subtasks

- [ ] Task 1 : Méthode service `updateEntity()` (AC: #5)
  - [ ] Ajouter `Multientity::updateEntity($entity_id, $label, $code, $color)` (transaction, escape, validation couleur, label requis, dol_syslog)
- [ ] Task 2 : Onglet admin (AC: #1)
  - [ ] Ajouter l'onglet « Entités » dans `multientityAdminPrepareHead()` (lib)
- [ ] Task 3 : Page `admin/entities.php` (AC: #2, #3, #4, #6, #7, #9, #10)
  - [ ] Bloc include main.inc.php multi-chemins, `$user->admin`, traductions
  - [ ] Actions POST (action=create / update / setactive) avec token + PRG
  - [ ] Vue liste + formulaire création + **formulaire d'édition pré-rempli via `?action=edit&entity_id=N`** (pré-chargé par `getEntity()`) — PAS de JS inline, même pattern que les pages admin Dolibarr standard
- [ ] Task 4 : i18n (AC: #8)
  - [ ] Clés ajoutées aux 5 langues, cohérentes
- [ ] Task 5 : Vérif
  - [ ] `php -l` ; cohérence des clés i18n entre les 5 langues
  - [ ] Test manuel (démo) : création entité 2 ; renommage entité 1 (permis) ; désactivation entité 1 tentée → refus affiché ; label vide → message ; couleur invalide → message ; entity_id inexistant → message ; double-submit après redirect (PRG) sans doublon

## Dev Notes

- **Réutiliser le service** : toute opération via `Multientity` (`dol_include_once('/multientity/class/multientity.class.php')`). **Aucun SQL** dans la page. [Source: story 2-1 ; architecture §3]
- **Garde entité 1** : `setActive()` refuse déjà la désactivation de l'entité 1 (retour <0). La page NE DOIT PAS proposer le bouton « désactiver » pour entity_id=1, et afficher `CannotDeactivateMainEntity` si le service refuse. [Source: story 2-1 AC#5]
- **updateEntity** : nouvelle méthode service à créer dans cette story — même rigueur que `createEntity` (transaction, `$db->escape()`, validation `/^#[0-9A-Fa-f]{6}$/`, label requis). Ne pas permettre de changer `entity_id`.
- **CSRF/PRG** : chaque action POST vérifie `if (GETPOST('token','alphanohtml') != newToken()) accessforbidden();` puis `header('Location: '.$_SERVER['PHP_SELF']); exit;`. Référence unique : le pattern de `admin/setup.php` du module. [Source: CLAUDE.dolibarr.md#CSRF, #11]
- **Couleur** : input `type=color` ou texte `#RRGGBB` ; afficher la pastille via `<span style="background:...">` échappé. Ne jamais injecter la couleur brute sans validation/escape.
- **Pattern de référence** : `dolibarr_Production_Interne/admin/setup.php` (structure page admin, `dol_get_fiche_head`, `setEventMessages`, form token) ; `admin/setup.php` du module (déjà conforme) pour le bloc d'inclusion.
- **i18n** : garder les clés alignées sur les 5 langues (le job CI `module-structure` vérifie l'alignement fr_FR ↔ autres).

### Project Structure Notes

- Fichiers : `admin/entities.php` (NEW), `class/multientity.class.php` (UPDATE : +updateEntity), `lib/multientity.lib.php` (UPDATE : +onglet Entités), `langs/*/multientity.lang` (UPDATE ×5).
- L'onglet « Entités » s'insère dans `multientityAdminPrepareHead()` avant « À propos ».

### References

- [Source: docs/planning-artifacts/epics.md#Epic-2] — story 2.2
- [Source: docs/planning-artifacts/prd.md#FR2] — CRUD entités, pas de suppression si données rattachées (désactivation)
- [Source: docs/planning-artifacts/architecture.md#3] — admin/entities.php
- [Source: docs/implementation-artifacts/2-1-service-multientity.md] — service réutilisé + garde entité 1
- [Source: CLAUDE.dolibarr.md#11][#CSRF] — sécurité & compat

## Dev Agent Record

### Agent Model Used

Sonnet (validate + dev + review 3-layer), coordination Opus. 2026-06-23.

### Debug Log References

`php -l` OK (entities.php, multientity.class.php, multientity.lib.php) ; clés i18n alignées sur les 5 langues.

### Completion Notes List

- 10 AC implémentés (validés + reviewés).
- Patches review appliqués : **C1-1** (validation regex couleur en SORTIE — anti-injection CSS sur attribut style, fallback —), **C1-2** (double `name="color"` supprimé : un seul input texte #RRGGBB par formulaire), **C2-1** (redirect erreur update → `?action=edit&entity_id=N`, préserve le contexte), **C1-3** (`!is_object()` au lieu de comparaison objet `<0`), **C1-4** (`action` en `alphanohtml`), **C2-2** (feedback si `action=edit` sans entity_id), **C2-3** (`active` borné 0/1), **C2-4** (erreur `listEntities()` remontée), **C1-5** (label échappé dans `dol_syslog`).
- C2-5 (race MAX+1) : déjà tranché en 2-1 (unicité protège l'intégrité) — non bloquant.

### File List

- admin/entities.php (NEW)
- class/multientity.class.php (UPDATE : +updateEntity)
- lib/multientity.lib.php (UPDATE : onglet Entités)
- langs/{fr_FR,en_US,de_DE,es_ES,it_IT}/multientity.lang (UPDATE : +16 clés)

- Correctif sécurité post-review auto (2026-06-23) : `$_SERVER['PHP_SELF']` échappé via `$selfUrl = dol_escape_htmltag(...)` dans toutes les sorties HTML (href/action) — anti-XSS réfléchi via PATH_INFO (MEDIUM). Les `header('Location')` restent en brut (non-XSS).
