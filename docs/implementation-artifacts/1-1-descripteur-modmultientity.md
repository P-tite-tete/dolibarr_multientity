# Story 1.1 : Descripteur modMultiEntity

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a administrateur Dolibarr de la suite P'tite Tête,
I want un descripteur de module `modMultiEntity` conforme et un module qui s'active/désactive proprement,
so that le multi-entité maison puisse être installé sur une instance Dolibarr 19→23+ sans erreur ni patch du core.

## Acceptance Criteria

1. Le descripteur `core/modules/modMultiEntity.class.php` déclare `numero = 351007` (plage P'tite Tête 351000-351099), `rights_class = 'multientity'`, famille `technic`.
2. Deux droits sont déclarés : `read` (`35100701`, label `PermissionRead`) et `manage` (`35100702`, label `PermissionManage`).
3. La version est lue depuis la source unique `lib/version.lib.php` (`MULTIENTITY_MODULE_VERSION`), jamais hardcodée dans le descripteur.
4. Les 2 tables (`multientity_entity`, `multientity_user_entity`) sont déclarées dans `$this->tables` et créées à l'activation via `_load_tables('/multientity/sql/')`.
5. La constante de config `MULTIENTITY_DEBUG` est déclarée dans `$this->const`.
6. Compatibilité déclarée : `phpmin = (7,4,0)`, `need_dolibarr_version = (19,0,0)` ; conflit déclaré avec `modMulticompany`.
7. Le module s'active **et** se désactive sans erreur PHP sur Dolibarr 19 / 21 / 23 ; `php -l` OK sur tous les fichiers.
8. À l'activation, l'entité `1` est enregistrée automatiquement et idempotemment dans `llx_multientity_entity` (cf. FR1 — la table est créée par la story 1.2, co-dépendance). **Critère de test** : `SELECT * FROM llx_multientity_entity WHERE entity_id=1` → 1 ligne (`label='Entité principale'`, `code='MAIN'`) ; ré-activation → toujours 1 ligne, pas de doublon.
9. Aucun hook actif n'est déclaré en Epic 1 (le gestionnaire de hooks arrive en Epic 3) : `module_parts['hooks'] = array()`, `triggers = 0`, pour garantir une activation propre sans classe manquante.

## Tasks / Subtasks

- [ ] Task 1 : Source unique de version (AC: #3)
  - [ ] Créer `lib/version.lib.php` avec `MULTIENTITY_MODULE_VERSION`, `MULTIENTITY_MIN_DOLIBARR_VERSION`, `MULTIENTITY_MAX_DOLIBARR_VERSION` + fonction `multientity_get_version()`
- [ ] Task 2 : Descripteur du module (AC: #1, #2, #5, #6, #9)
  - [ ] `core/modules/modMultiEntity.class.php` étendant `DolibarrModules` : identité, description, picto
  - [ ] Droits `read`/`manage` ; `const` `MULTIENTITY_DEBUG` ; `depends`/`conflictwith` ; bornes de compat
  - [ ] `module_parts` sans hooks ; `$this->tables` avec les 2 tables ; lecture version depuis `version.lib.php`
- [ ] Task 3 : Activation / désactivation (AC: #4, #7, #8)
  - [ ] `init()` : `_load_tables('/multientity/sql/')` puis `registerDefaultEntity()` (INSERT … ON DUPLICATE KEY UPDATE)
  - [ ] `remove()` : conservation des données (réversible, NFR-C2)
- [ ] Task 4 : Vérification (AC: #7)
  - [ ] `php -l` sur tous les fichiers PHP créés
  - [ ] Test d'activation/désactivation sur Dolibarr local (`dolibarr-23/`)

## Dev Notes

- **Principe directeur (NE PAS DÉVIER)** : zéro patch du core. On réutilise le socle multi-entité natif (colonne `entity` partout, `getEntity()` strict, `$_SESSION['dol_entity']` lu par `master.inc.php`). Le module n'ajoute QUE l'orchestration. [Source: docs/planning-artifacts/architecture.md#1]
- **Module folder** : le module se déploie dans `htdocs/custom/multientity/`. Tous les chemins internes (`dol_include_once`, `_load_tables`) référencent `/multientity/...`. [Source: CLAUDE.dolibarr.md#1]
- **Version source unique** : convention P'tite Tête — la version vit dans `lib/version.lib.php`, le descripteur la lit. [Source: CLAUDE.dolibarr.md#8]
- **Droits** : utiliser `$user->hasRight('multientity','read'|'manage')` côté consommateurs (pas `$user->rights->`). [Source: CLAUDE.dolibarr.md#11]
- **Tables transverses** : `_load_tables` exécute les `.sql` de `sql/` et substitue `llx_` → `MAIN_DB_PREFIX`. Les 2 tables sont volontairement GLOBALES (pas de colonne `entity`) — c'est de la méta-gestion d'entités. [Source: docs/planning-artifacts/architecture.md#2]
- **Idempotence entité 1** : `INSERT … ON DUPLICATE KEY UPDATE tms = tms` sur la clé unique `entity_id`. Pas de `IF NOT EXISTS`. [Source: CLAUDE.dolibarr.md#4]
- **Hooks différés** : déclarer des hooks pointant vers `core/hookcontrols/actions_multientity.class.php` AVANT que la classe existe (Epic 3) risquerait des warnings ; on laisse `hooks => array()` en Epic 1. Décision actée — voir AC #9.
- **Pattern de référence** : `modProductionInterne.class.php` (même plage 351001) pour la structure descripteur/init/remove/getModuleRights.

#### Notes de validation (intégrées)

- **PHPDoc `@version` = exception** : le bloc PHPDoc `@version 0.1.0` en tête de fichier est hardcodé par nature (impossible d'y interpoler une constante). Seul `$this->version = MULTIENTITY_MODULE_VERSION` fait foi ; le `@version` PHPDoc est mis à jour manuellement via `./update_version.sh` à chaque release. Le grep anti-hardcode (§8) doit ignorer les blocs PHPDoc.
- **Emplacement imposé** : `require_once dirname(__DIR__, 2) . '/lib/version.lib.php'` ET `_load_tables('/multientity/sql/')` supposent une installation sous `htdocs/custom/multientity/`. À documenter comme prérequis dans le README (Epic 5.3).
- **Pas de surcharge `getModuleRights()`** : le tableau `$this->rights` classique suffit pour 2 droits simples et reste compatible 19→23+. Décision assumée.
- **Révision Epic 3** : l'AC #9 (hooks vides) sera révisé en story 3.1/3.2 — `module_parts['hooks']` recevra le contexte du `actions_multientity` et `triggers` pourra passer à `1`. Ne pas créer de nouvelle story « activer les hooks » sans modifier ce descripteur.

### Project Structure Notes

- Fichiers créés par cette story (co-livrés avec 1.2 SQL et 1.3 i18n/lib car interdépendants à l'activation) :
  - `core/modules/modMultiEntity.class.php` (NEW)
  - `lib/version.lib.php` (NEW)
- Conforme à la structure standard module Dolibarr `custom/` [Source: CLAUDE.dolibarr.md#1]. Aucune variance détectée.
- Co-dépendance assumée : l'AC #8 (enregistrement entité 1) requiert la table de la story 1.2. Les stories 1.1/1.2/1.3 forment l'Epic 1 « Fondations » et sont livrées ensemble en v0.1.0.

### References

- [Source: docs/planning-artifacts/epics.md#Epic-1] — Fondations module & schéma, story 1.1
- [Source: docs/planning-artifacts/prd.md#FR1] — table des entités + enregistrement auto entité 1
- [Source: docs/planning-artifacts/architecture.md#1] — principe « réutiliser le natif, n'ajouter que l'orchestration »
- [Source: docs/planning-artifacts/architecture.md#3] — composants (descripteur, sql, lib)
- [Source: CLAUDE.dolibarr.md#2] — plage d'IDs 351000-351099, numero 351007
- [Source: CLAUDE.dolibarr.md#8] — source unique de version
- [Source: CLAUDE.dolibarr.md#11] — compatibilité Dolibarr 19→23+

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (implémentation hors cycle régularisée a posteriori)

### Debug Log References

### Completion Notes List

- Code v0.1.0 implémenté le 2026-06-23. `php -l` OK sur les 5 fichiers PHP.
- Décision : `module_parts['hooks']` laissé vide (hooks en Epic 3) pour garantir activation propre.
- **AC #7 partiel** : activation/désactivation réelle pas encore testée sur Dolibarr 19/21/23 (test à faire sur `dolibarr-23/`). AC non clos tant que non vérifié.

### File List

- core/modules/modMultiEntity.class.php
- lib/version.lib.php
