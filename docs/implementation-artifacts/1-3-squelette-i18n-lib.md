# Story 1.3 : Squelette i18n + lib

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a administrateur Dolibarr (et utilisateur de 5 locales),
I want les fichiers de langue (5 langues) et la lib de helpers du module,
so that le module affiche des libellés traduits et que les pages admin disposent de leurs onglets et helpers communs.

## Acceptance Criteria

1. `langs/{fr_FR,en_US,de_DE,es_ES,it_IT}/multientity.lang` existent (5 langues OBLIGATOIRES) avec les clés de base : `Module351007Name`, `Module351007Desc`, `MultiEntity`, `PermissionRead`, `PermissionManage`, `MultiEntitySetup`, `MultiEntityAbout`, `MultiEntityDebugMode`, `MultiEntityIsolationNote`, `MultiEntityIsolationDesc`.
2. Les clés `Module351007Name` / `Module351007Desc` correspondent au `numero` du module (affichage nom/description dans la liste des modules Dolibarr).
3. `lib/multientity.lib.php` fournit : `multientity_get_base_url()`, `multientityAdminPrepareHead()` (onglets Settings + About), `multientity_get_entity_label($db, $entity_id=null)`. Le fallback de `multientity_get_entity_label` (entité absente de la table) DOIT être traduit (`$langs->trans('Entity').' '.$entity_id`), pas une chaîne française littérale (FR11).
4. `multientityAdminPrepareHead()` appelle `complete_head_from_modules(...)` pour l'extensibilité.
5. Pages admin minimales conformes : `admin/setup.php` (config `MULTIENTITY_DEBUG`, CSRF via `newToken()`, `$user->admin`) et `admin/about.php` (version, éditeur, licence, note d'isolation).
6. Sécurité code : guard `if (!defined('DOLIBARR_INC_FOR_MODULES')) { ... }` exigée en tête de chaque fichier admin (convention CLAUDE.dolibarr.md §sécurité) ; `$user->admin` / `accessforbidden()` ; `GETPOST`/`GETPOSTINT` typé ; `newToken()` vérifié sur POST **uniquement sur `setup.php`** (action `setvalue`) — `about.php` est read-only, pas de formulaire POST ; sorties échappées (`dol_escape_htmltag`) ; pas d'accès `$_POST`/`$_GET` direct.
7. Pas de clé de traduction manquante au chargement des pages admin ; cohérence des 5 fichiers (mêmes clés).

## Tasks / Subtasks

- [ ] Task 1 : i18n 5 langues (AC: #1, #2, #7)
  - [ ] `multientity.lang` × 5 avec clés de base cohérentes (+ clé `Entity` pour le fallback)
  - [ ] Vérifier la cohérence des clés entre les 5 fichiers (diff des clés `grep '=' ... | cut -d= -f1 | sort`)
- [ ] Task 2 : Lib helpers (AC: #3, #4)
  - [ ] `lib/multientity.lib.php` : base_url, AdminPrepareHead, get_entity_label
- [ ] Task 3 : Pages admin (AC: #5, #6)
  - [ ] `admin/setup.php` : formulaire MULTIENTITY_DEBUG, CSRF, head via lib
  - [ ] `admin/about.php` : infos module (lecture `version.lib.php`)

## Dev Notes

- **5 langues obligatoires** : fr_FR, en_US, de_DE, es_ES, it_IT — convention dure P'tite Tête. [Source: CLAUDE.dolibarr.md#1]
- **Clé nom/description module** : Dolibarr cherche `Module{numero}Name` / `Module{numero}Desc` → ici `Module351007Name`/`Module351007Desc`. [Source: pattern descripteur Dolibarr]
- **Sécurité admin** : `!defined('DOLIBARR_INC_FOR_MODULES')` non requis ici (pages admin, pas hook), mais `accessforbidden()` si `!$user->admin`, `newToken()` sur POST, `GETPOST`/`GETPOSTINT` typés. [Source: CLAUDE.dolibarr.md#11]
- **Compat 19+** : `getDolGlobalInt()`/`getDolGlobalString()`, `load_fiche_titre()`, `dol_get_fiche_head/end()`. [Source: CLAUDE.dolibarr.md#11]
- **prepareHead** : pattern `productioninterne_admin_prepare_head()` (onglets + `complete_head_from_modules`). [Source: dolibarr_Production_Interne/lib/productioninterne.lib.php]
- **`multientity_get_entity_label`** : SELECT sur `llx_multientity_entity WHERE entity_id = (int)` ; fallback traduit (`$langs->trans('Entity')`). Sera réutilisé par l'indicateur d'entité courante (FR9, Epic 3). Ajouter la clé `Entity` aux 5 fichiers lang.
- **`multientity_get_base_url()`** : retourne le chemin relatif à `DOL_URL_ROOT` (ex. `/custom/multientity`) via `dol_buildpath('/multientity', 1)` puis soustraction de `DOL_URL_ROOT`. Si `DOL_URL_ROOT` vide → retourne le chemin complet. Tester avec `DOL_URL_ROOT=''` et `DOL_URL_ROOT='/dolibarr'`.
- **`about.php`** inclut `version.lib.php` via `dol_include_once` (idempotent même si déjà chargé par le descripteur).

#### Notes de validation (intégrées)

- **Guard `DOLIBARR_INC_FOR_MODULES`** : le code actuel de `setup.php`/`about.php` ne l'a PAS → à corriger (la review tranchera la forme exacte ; convention projet l'exige en tête de fichier admin).
- **Droits Epic 2** : les pages admin d'Epic 1 ne vérifient que `$user->admin`. Les pages d'Epic 2 (`entities.php`, `user_entities.php`) devront ajouter `$user->hasRight('multientity','manage')`.

### Project Structure Notes

- Fichiers créés (NEW) : `lib/multientity.lib.php`, `admin/setup.php`, `admin/about.php`, `langs/*/multientity.lang` (×5).
- Conforme structure standard `custom/` + 5 langues. Aucune variance.

### References

- [Source: docs/planning-artifacts/epics.md#Epic-1] — story 1.3
- [Source: docs/planning-artifacts/architecture.md#3] — lib + langs + admin
- [Source: docs/planning-artifacts/prd.md#FR11] — traductions 5 langues
- [Source: CLAUDE.dolibarr.md#1] — 5 langues obligatoires
- [Source: CLAUDE.dolibarr.md#11] — sécurité & compat code

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (implémentation hors cycle régularisée a posteriori)

### Debug Log References

### Completion Notes List

- i18n 5 langues + lib + 2 pages admin implémentées v0.1.0 le 2026-06-23. `php -l` OK.

### File List

- lib/multientity.lib.php
- admin/setup.php
- admin/about.php
- langs/fr_FR/multientity.lang
- langs/en_US/multientity.lang
- langs/de_DE/multientity.lang
- langs/es_ES/multientity.lang
- langs/it_IT/multientity.lang
