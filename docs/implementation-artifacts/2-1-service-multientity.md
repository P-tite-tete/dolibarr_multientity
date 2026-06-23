# Story 2.1 : Service Multientity

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a développeur du module (et futures pages admin / API),
I want une classe service `Multientity` encapsulant l'accès aux deux tables d'orchestration,
so that la création, la liste, l'activation des entités et la résolution des entités autorisées d'un utilisateur passent par un point unique, testable et sûr (pas de SQL dispersé dans les pages).

## Acceptance Criteria

1. Classe `class/multientity.class.php` (`class Multientity`), constructeur `__construct(DoliDB $db)`, en-tête PHPDoc P'tite Tête, `@version` lu de la convention.
2. `listEntities($activeOnly = false)` : retourne toutes les entités de `llx_multientity_entity` (objets avec `entity_id, label, code, color, active`), triées par `entity_id`. Filtre `active=1` si `$activeOnly`.
3. `getEntity($entity_id)` : retourne une entité unique (ou `null` si absente).
4. `createEntity($label, $code = null, $color = null, $entity_id = null)` : insère une nouvelle entité. Si `$entity_id` non fourni, calcule `COALESCE(MAX(entity_id), 1) + 1` (safe table vide → 2). **Transaction** ; `$db->escape()` sur tout ; pose `date_creation = $db->idate(dol_now())` ; renvoie le `entity_id` créé ou `<0` en cas d'erreur. Refus si `entity_id` déjà présent (clé unique). `$color` non null doit matcher `/^#[0-9A-Fa-f]{6}$/` sinon stocké `null` + log `LOG_WARNING`.
5. `setActive($entity_id, $active)` : active/désactive une entité. **Guard en tête de méthode AVANT tout SQL** : si `$entity_id == 1 && !$active` → retour `<0` + `dol_syslog(LOG_WARNING)` (pas de transaction ouverte). Sinon `$db->begin()` → `UPDATE` → `commit()` ; `rollback()` sur erreur SQL. Retourne `1` si OK.
6. **Pas de suppression** d'entité dans le service (désactivation seulement) — aucune méthode `delete()`. Documenté.
7. `getEntityLabel($entity_id)` : **appelle** `multientity_get_entity_label($this->db, $entity_id)` (après `dol_include_once('/multientity/lib/multientity.lib.php')`) — ne duplique PAS la requête. Source unique.
8. `getUserEntities($fk_user)` : retourne les `entity_id` autorisés d'un utilisateur depuis `llx_multientity_user_entity`, **uniquement si l'utilisateur est actif** : `INNER JOIN llx_user u ON u.rowid = ue.fk_user AND u.statut = 1` (pas de filtre `u.entity`). Renvoie aussi `is_default`. User supprimé/inactif → liste vide (gestion orphelins, A2 retro E1).
9. `getDefaultEntity($fk_user)` : `entity_id` par défaut autorisé (`is_default=1`), sinon `1`. Le fallback `1` est garanti valide (entité 1 toujours active via la garde AC #5) — pas de vérification supplémentaire.
10. Sécurité/convention : `MAIN_DB_PREFIX`, `$db->escape()`, transactions sur écritures, `dol_syslog` sur erreurs/refus, **aucune** requête brute non préparée, aucune colonne `entity` ajoutée (tables transverses).
11. Tests (décision A5 tranchée : **pas** de `composer.json`/PHPUnit en E2) : fournir `test/smoke_multientity.php` — script PHP standalone (`dol_include_once`, instanciation, `assert()`) couvrant create→list→setActive(garde entité 1)→getUserEntities. La CI tests reste conditionnelle.

## Tasks / Subtasks

- [ ] Task 1 : Squelette de la classe (AC: #1, #10)
  - [ ] `class/multientity.class.php` : header, propriétés `$db`, `$error`, `$errors`
- [ ] Task 2 : Lecture (AC: #2, #3, #7)
  - [ ] `listEntities()`, `getEntity()`, `getEntityLabel()`
- [ ] Task 3 : Écriture (AC: #4, #5, #6)
  - [ ] `createEntity()` (calcul entity_id, transaction, unicité), `setActive()` (garde entité 1)
- [ ] Task 4 : Résolution utilisateur (AC: #8, #9)
  - [ ] `getUserEntities()` (jointure user actif, orphelins), `getDefaultEntity()`
- [ ] Task 5 : Vérif (AC: #11)
  - [ ] `php -l` ; smoke test create→list→setActive→getUserEntities

## Dev Notes

- **Tables transverses** : `llx_multientity_entity` (entity_id UNIQUE, label, code, color, active, date_creation, tms) et `llx_multientity_user_entity` (fk_user, entity_id, is_default, UNIQUE(fk_user,entity_id)). Pas de colonne `entity`. [Source: docs/planning-artifacts/architecture.md#2]
- **Service = point unique** : les pages admin (2.2/2.4), l'init (2.3), le contrôle d'accès (E3) et l'API (E6) consomment cette classe — ne pas dupliquer le SQL ailleurs. [Source: docs/planning-artifacts/architecture.md#3]
- **Garde entité 1** : l'entité principale ne se désactive jamais (sinon instance inutilisable). [Source: prd FR2]
- **Orphelins `fk_user`** : pas de FK vers `llx_user` → `getUserEntities()` joint `llx_user` et filtre `statut=1` ; un user supprimé ne doit jamais « hériter » d'entités. Lien isolation E3. [Source: epic-1-retro-2026-06-23.md A2 ; story 1.2 notes]
- **Conventions** : `MAIN_DB_PREFIX`, `$db->escape()`, `$db->begin()/commit()/rollback()`, `dol_syslog(__METHOD__...)`, `getDolGlobalString` si besoin. [Source: CLAUDE.dolibarr.md#4][#7]
- **Pattern de référence** : structure de classe service Dolibarr (propriétés `$db/$error/$errors`, méthodes retournant `>0`/`<0`). S'inspirer des classes `class/*.class.php` des autres modules P'tite Tête (sans CRUD generator lourd ici).
- **i18n** : réutiliser la clé `Entity` (déjà ajoutée en 1.3) ; le service ne renvoie pas de HTML.
- **MySQL strict** : si `ORDER BY entity_id`, l'inclure dans le SELECT (déjà le cas). Pas de GROUP BY non agrégé.

#### Notes de validation (intégrées)

- `createEntity` : `COALESCE(MAX(entity_id),1)+1` (safe table vide) ; `date_creation = $db->idate(dol_now())` (pattern `registerDefaultEntity`) ; couleur validée `/^#[0-9A-Fa-f]{6}$/` sinon null+log.
- `setActive` : garde `entity_id=1 && !$active` AVANT `begin()` (pas de transaction zombie).
- `getUserEntities` : `INNER JOIN llx_user u ON u.rowid = ue.fk_user AND u.statut = 1` ; orphelins → liste vide.
- `getEntityLabel` : délègue à `multientity_get_entity_label()` (source unique, pas de SQL dupliqué).
- A5 tranché : pas de `composer.json`/PHPUnit ; smoke test PHP standalone `test/smoke_multientity.php`.

### Project Structure Notes

- Fichier créé (NEW) : `class/multientity.class.php`.
- Le dossier `class/` n'existe pas encore → à créer (cohérent avec architecture §3 et CLAUDE.dolibarr.md §1).
- Aucune modification du descripteur requise pour cette story (le service est chargé via `dol_include_once('/multientity/class/multientity.class.php')` par les consommateurs).

### References

- [Source: docs/planning-artifacts/epics.md#Epic-2] — story 2.1
- [Source: docs/planning-artifacts/architecture.md#2][#3] — schéma + composants
- [Source: docs/planning-artifacts/prd.md#FR2][#FR4] — CRUD entités (pas de suppression), affectation users
- [Source: docs/implementation-artifacts/epic-1-retro-2026-06-23.md] — A2 (orphelins fk_user)
- [Source: CLAUDE.dolibarr.md#4][#6][#7] — SQL, nommage, logs

## Dev Agent Record

### Agent Model Used

Sonnet (dev) + Sonnet (validate + review 3-layer), coordination Opus. 2026-06-23.

### Debug Log References

`php -l` OK sur class/multientity.class.php et test/smoke_multientity.php.

### Completion Notes List

- Service implémenté conforme aux 11 AC (validés + reviewés).
- Patches review appliqués : F-01 (refus label vide, retour -2), F-08 (LIMIT 1 sur getDefaultEntity), F-09 (refus entity_id<=0, retour -3), F-03 (idate inline), F-05 (smoke test CLI-only).
- Findings rejetés (avec preuve) : **F-04** (colonne `llx_user.statut` confirmée par DDL Dolibarr 23 — pas `status` ; `u.statut=1` correct) ; **F-02** (`FOR UPDATE` non ajouté : l'unicité `uk_multientity_entity` garantit déjà l'intégrité, createEntity = action admin rare).
- À trancher en E3 : **F-12** — `getUserEntities` ne filtre pas les entités désactivées (`active=0`) ; le switch (E3) devra décider d'exposer ou non une entité autorisée mais désactivée.

### File List

- class/multientity.class.php (NEW)
- test/smoke_multientity.php (NEW)
