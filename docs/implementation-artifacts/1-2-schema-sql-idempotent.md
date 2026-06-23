# Story 1.2 : Schéma SQL idempotent

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a administrateur Dolibarr,
I want les deux tables d'orchestration multi-entité créées de façon idempotente à l'activation,
so that le module dispose de son stockage (entités gérées + affectations users) sans risque de doublon ni d'erreur en réinstallation.

## Acceptance Criteria

1. `sql/llx_multientity_entity.sql` crée la table des entités : `rowid` (PK auto), `entity_id` (NOT NULL, UNIQUE), `label` (NOT NULL), `code`, `color`, `active` (DEFAULT 1), `date_creation`, `tms`.
2. `sql/llx_multientity_user_entity.sql` crée la table d'affectation : `rowid` (PK auto), `fk_user` (NOT NULL), `entity_id` (NOT NULL), `is_default` (DEFAULT 0), `tms` ; `UNIQUE KEY (fk_user, entity_id)` + index `fk_user`.
3. **Aucune colonne `entity`** dans ces 2 tables : ce sont des tables transverses (méta-gestion), volontairement globales — documenté en commentaire d'en-tête SQL.
4. Préfixe `llx_` dans les fichiers SQL (substitué en `MAIN_DB_PREFIX` par `_load_tables`) ; `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.
5. En-têtes SQL conformes P'tite Tête (`-- Date`, `-- Version`, `-- Description`, `-- Author`, `-- Copyright`, `-- License`).
6. Réinstallation idempotente : à la ré-activation, `_load_tables` tolère l'erreur SQL 1050 (« Table already exists ») sans interrompre, et l'entité 1 est ré-insérée via `ON DUPLICATE KEY UPDATE`. **Le fallthrough sur 1050 n'est pas une garantie d'API contractuelle** — à vérifier par un test de ré-activation réelle sur la cible (cf. Task 3) ; en cas de doute, fournir un `update_*.sql` `information_schema` dès v0.1.0.
7. Pas de `CREATE TABLE IF NOT EXISTS` ni `IF EXISTS` ; les migrations futures utiliseront `information_schema` + `PREPARE`.

## Tasks / Subtasks

- [ ] Task 1 : Table entités (AC: #1, #3, #4, #5)
  - [ ] `sql/llx_multientity_entity.sql` avec `UNIQUE KEY uk_multientity_entity (entity_id)`
- [ ] Task 2 : Table affectations (AC: #2, #3, #4, #5)
  - [ ] `sql/llx_multientity_user_entity.sql` avec `uk_multientity_user_entity` + `idx_multientity_user`
- [ ] Task 3 : Idempotence (AC: #6, #7)
  - [ ] Test de ré-activation réelle sur `dolibarr-23/` : désactiver puis réactiver → aucune erreur bloquante, toujours 1 ligne entité 1
  - [ ] Créer le squelette `sql/update_multientity_0.1.0_0.2.0.sql` (pattern `information_schema` + `PREPARE`, commenté)

## Dev Notes

- **Tables transverses sans `entity`** : exception documentée à la règle multi-tenant habituelle. `entity_id` y est une donnée métier (la valeur référencée), pas un filtre de tenant. [Source: docs/planning-artifacts/architecture.md#2]
- **`_load_tables('/multientity/sql/')`** exécute les `.sql` et substitue `llx_` → `MAIN_DB_PREFIX`. Écrire `llx_` littéral dans les fichiers. [Source: CLAUDE.dolibarr.md#4]
- **Idempotence** : pas de `IF NOT EXISTS`. Pour les migrations ultérieures (`update_*.sql`), utiliser le pattern `information_schema` + `PREPARE`. [Source: CLAUDE.dolibarr.md#4]
- **Enregistrement entité 1** : réalisé par `init()` du descripteur (story 1.1), pas dans le `.sql`. La table doit donc exister avant l'INSERT — ordre garanti par `_load_tables` avant `registerDefaultEntity()`.
- **Pattern de référence** : `dolibarr_Production_Interne/sql/llx_productioninterne_production.sql` (en-têtes, InnoDB/utf8mb4) — mais SANS colonne `entity` ici.

#### Notes de validation (intégrées)

- **Pas de `FOREIGN KEY` sur `fk_user`** (cohérent avec le pattern Dolibarr, FK rarement déclarées). Conséquence sécurité : à la suppression d'un user, ses affectations restent orphelines dans `llx_multientity_user_entity`. **Lien isolation** : le switch d'entité (Epic 3) et le service `Multientity` (Epic 2) DOIVENT valider que `fk_user` correspond à un user actif et nettoyer/ignorer les orphelins — sinon risque qu'un `rowid` réattribué hérite d'entités. À tracer comme exigence d'Epic 2/3.
- **`date_creation`** : `DEFAULT NULL` au niveau schéma (l'INSERT `registerDefaultEntity` fournit `dol_now()`). Les insertions de l'Epic 2 (`createEntity`) DOIVENT renseigner `date_creation`. NULL accepté uniquement pour rétrocompat.
- **`tms`** : une seule colonne `timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` par table → conforme MySQL 5.7 strict (la limite « une seule TIMESTAMP auto » est respectée).
- **Template migration** : prévoir dès maintenant `sql/update_multientity_0.1.0_0.2.0.sql` (squelette `information_schema` + `PREPARE`, vide mais commenté) comme référence pour les ajouts de colonnes futurs (architecture §3 liste `sql/update_*.sql`).

### Project Structure Notes

- Fichiers créés (NEW) : `sql/llx_multientity_entity.sql`, `sql/llx_multientity_user_entity.sql`.
- Schéma conforme à architecture.md §2 (noms de colonnes, clés, contraintes). Aucune variance.

### References

- [Source: docs/planning-artifacts/epics.md#Epic-1] — story 1.2
- [Source: docs/planning-artifacts/architecture.md#2] — schéma des 2 tables
- [Source: docs/planning-artifacts/prd.md#FR1][#FR4] — table entités + affectation users
- [Source: CLAUDE.dolibarr.md#4] — règles SQL/MySQL, interdiction IF NOT EXISTS

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (implémentation hors cycle régularisée a posteriori)

### Debug Log References

### Completion Notes List

- Tables créées v0.1.0 le 2026-06-23, sans colonne `entity` (transverses).

### File List

- sql/llx_multientity_entity.sql
- sql/llx_multientity_user_entity.sql
