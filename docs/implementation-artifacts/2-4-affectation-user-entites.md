# Story 2.4 : Affectation utilisateur ↔ entités

---
baseline_commit: c5cfa8d7cbf551b8dde1db2e6d2ef92ae9eb1988
---

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a super-administrateur,
I want associer un utilisateur à une ou plusieurs entités autorisées et définir son entité par défaut,
so that chaque utilisateur ne pourra accéder (E3) qu'aux sociétés qui le concernent, avec une entité d'ouverture par défaut (FR4).

## Acceptance Criteria

1. Page `admin/user_entities.php` accessible via un onglet « Utilisateurs ↔ entités » ajouté à `multientityAdminPrepareHead()`. Sécurité : `$user->admin`, CSRF sur POST, `GETPOST`/`GETPOSTINT` typés, sorties échappées.
2. Sélection d'un utilisateur via `$form->select_dolusers()` (utilisateurs actifs). Une fois un user choisi (`?fk_user=N`), afficher ses affectations.
3. Liste des entités **actives** (`Multientity::listEntities(true)`) avec, pour chacune, une case « autorisée » et un radio « par défaut ». L'entité 1 est toujours proposée.
4. **Service** — nouvelles méthodes :
   - `setUserEntities($fk_user, array $entity_ids, $default_entity_id)` : remplace l'ensemble des affectations d'un user (DELETE des lignes du user puis INSERT des `entity_ids`), en **transaction** ; `is_default=1` sur `$default_entity_id`. Valide que `$default_entity_id ∈ $entity_ids`. Valide que chaque `entity_id` existe et est actif. `fk_user` doit exister. Retour `1`/`<0`.
   - (réutilise `getUserEntities($fk_user)` existant pour pré-cocher.)
5. Règles métier : si au moins une entité est autorisée, **une entité par défaut est obligatoire** et doit faire partie des autorisées (sinon erreur). Si aucune entité cochée → suppression de toutes les affectations du user (autorisé, mais averti via `NoEntitySelected` en **warning**, pas error). Quand `$entity_ids` est vide, `$default_entity_id` est **ignoré** (`null`/`0` équivalents, aucune contrainte de défaut).
6. **Isolation/cohérence** : seules des entités **existantes et actives** peuvent être affectées (rejet sinon). La table `llx_multientity_user_entity` reste transverse (pas de colonne `entity`).
7. Robustesse : `setUserEntities` en transaction atomique (DELETE+INSERT) — rollback si une insertion échoue ; pas d'état partiel. Doublon `(fk_user, entity_id)` géré par la clé UNIQUE.
8. i18n : clés aux 5 langues (`UserEntities`, `SelectUser`, `AuthorizedEntities`, `DefaultEntity`, `AssignmentsSaved`, `DefaultMustBeAuthorized`, `NoEntitySelected`).
9. Compat 19→23+ : `$form->select_dolusers`, `getDolGlobalString`, pas de `$_POST` direct, `header()`+`exit`, PRG.
10. Lien E3 : ces affectations sont la **source de vérité** des entités autorisées que le switch (E3) validera côté serveur. Ne pas dupliquer cette logique ailleurs.

## Tasks / Subtasks

- [x] Task 1 : Service `setUserEntities()` (AC: #4, #5, #6, #7)
  - [x] DELETE+INSERT transactionnel ; validations (user existe, entités existent+actives, défaut ∈ autorisées)
- [x] Task 2 : Onglet admin (AC: #1)
  - [x] Onglet « Utilisateurs ↔ entités » dans `multientityAdminPrepareHead()`
- [x] Task 3 : Page `admin/user_entities.php` (AC: #1, #2, #3, #9)
  - [x] Sélecteur user ; tableau entités actives (case autorisée + radio défaut, pré-cochés via getUserEntities) ; POST `action=save` (token, PRG)
- [x] Task 4 : i18n (AC: #8) + Vérif (php -l, cohérence clés, test démo : user A↔{1,2} défaut 2 ; relecture)

## Dev Notes

- **Service = source unique** : aucune écriture/lecture directe de `llx_multientity_user_entity` hors `Multientity`. La page n'appelle que le service. [Source: 2-1, architecture §3]
- **`setUserEntities` (atomicité)** : `begin()` → `DELETE FROM llx_multientity_user_entity WHERE fk_user = (int)` → boucle `INSERT (fk_user, entity_id, is_default)` → `commit()` ; `rollback()` sur toute erreur. Valider AVANT d'écrire : `fk_user` existe (`llx_user`), chaque `entity_id` via `getEntity()` actif, `default ∈ entity_ids`. [Source: CLAUDE.dolibarr.md#4]
- **Défaut obligatoire** : cohérent avec `getDefaultEntity()` (2-1) qui retombe sur 1 ; mais si le user a des entités autorisées, l'une doit porter `is_default=1` pour un comportement déterministe au login (E3.1). [Source: 2-1 AC#9]
- **Sélecteur utilisateurs** : `$form->select_dolusers($selected, 'fk_user', 1)` (1 = montrer uniquement actifs si supporté ; sinon filtrer). [Source: core]
- **Validation entités actives** : empêcher d'affecter une entité désactivée (sinon un user aurait accès à une société hors service). Rejet + message. [Source: prd FR4/FR7]
- **Isolation** : table transverse globale ; ne jamais filtrer par `$conf->entity`. [Source: architecture §2]
- **Pattern page** : structure `admin/entities.php` (actions + PRG + token + dol_get_fiche_head).

#### Notes de validation (intégrées)

- **H1 — user inactif** : `setUserEntities` valide existence **ET** `statut=1` dans `llx_user` ; si user inexistant/inactif → retour `-2` + `$this->error` + `dol_syslog(LOG_WARNING)`. **Jamais** insérer d'affectations pour un user inactif (sinon orphelins invisibles via `getUserEntities`).
- **H2 — typage POST** : la page récupère les entités cochées via `array_map('intval', (array) GETPOST('entity_ids', 'array'))` puis `array_filter(fn>0)` ; `default_entity_id` via `GETPOSTINT`. Ne pas compter sur un cast interne au service.
- **M1 — dédup** : `array_unique($entity_ids)` AVANT `begin()` (évite violation UNIQUE sur doublon d'input).
- **M3 — audit (NFR-S3)** : tout refus (`fk_user` invalide/inactif, `entity_id` inexistant/inactif, défaut hors autorisées) → `dol_syslog(__METHOD__.' Refus ... fk_user='.$fk_user, LOG_WARNING)`.
- **L2 — sélecteur** : `$form->select_dolusers($selected, 'fk_user', 1, array(), 0, '', 0)` (7e arg `$enabledisabledusers=0` exclut les désactivés). Vérifier la signature sur la cible.

### Project Structure Notes

- `class/multientity.class.php` (UPDATE : +setUserEntities)
- `admin/user_entities.php` (NEW)
- `lib/multientity.lib.php` (UPDATE : onglet « Utilisateurs ↔ entités »)
- `langs/*/multientity.lang` (UPDATE ×5)

### References

- [Source: docs/planning-artifacts/epics.md#Epic-2] — story 2.4
- [Source: docs/planning-artifacts/prd.md#FR4][#FR7] — affectation user↔entités, base du contrôle d'accès
- [Source: docs/planning-artifacts/architecture.md#2][#3] — table de liaison, composants
- [Source: docs/implementation-artifacts/2-1-service-multientity.md] — getUserEntities/getDefaultEntity réutilisés
- [Source: CLAUDE.dolibarr.md#4][#11] — SQL/transactions, sécurité, compat

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Debug Log References

Aucun blocage. php -l OK sur les 3 fichiers PHP. 7 clés i18n cohérentes ×5 langues.

### Completion Notes List

- Task 1 : `setUserEntities($fk_user, array $entity_ids, $default_entity_id)` ajouté à `Multientity`. Validations : user actif (SELECT rowid FROM llx_user WHERE statut=1), chaque entity via getEntity() + active=1, default ∈ ids (retours -2/-3/-4). Transaction atomique begin/DELETE/INSERT loop/commit ; rollback sur toute erreur SQL.
- Task 2 : onglet `UserEntities` → `admin/user_entities.php` (clé `userentities`) inséré entre `entities` et `about` dans `multientityAdminPrepareHead()`.
- Task 3 : `admin/user_entities.php` créé. Include multi-chemins, `$user->admin`, CSRF sur `action=save`, `GETPOSTINT`/`GETPOST('action','alphanohtml')`, `GETPOST('entity_ids','array')` + cast+filter, PRG. Vue : sélecteur `select_dolusers` (arg 7=0 exclut désactivés), tableau entités actives avec checkbox + radio pré-cochés via `getUserEntities`.
- Task 4 : 7 clés story 2.4 ajoutées dans fr_FR, en_US, de_DE, es_ES, it_IT.

### Review notes (post-review)

- Patches appliqués : **B2** (guard `$fk_user > 0` avant `setUserEntities`), **B6** (`exit;` après `accessforbidden()`), **E3** (messages de refus localisés : -2/-3 → `EntityNotFound`, -4 → `DefaultMustBeAuthorized`).
- Différés/documentés (non bloquants) : **E7** (verrou `FOR UPDATE` anti-lost-update concurrent admin-admin — faible probabilité en usage admin ; last-write-wins assumé), **E8** (validation N entités via `IN(...)` au lieu de N SELECT — micro-optimisation).
- Isolation confirmée : table transverse, aucun filtre `$conf->entity`.

### File List

- class/multientity.class.php (modifié : +setUserEntities)
- lib/multientity.lib.php (modifié : onglet UserEntities)
- admin/user_entities.php (nouveau)
- langs/fr_FR/multientity.lang (modifié : +7 clés)
- langs/en_US/multientity.lang (modifié : +7 clés)
- langs/de_DE/multientity.lang (modifié : +7 clés)
- langs/es_ES/multientity.lang (modifié : +7 clés)
- langs/it_IT/multientity.lang (modifié : +7 clés)

## Change Log

- 2026-06-23 : Story 2.4 implémentée — setUserEntities + onglet admin + page user_entities.php + i18n ×5

- Correctif sécurité post-review auto (2026-06-23) : `$_SERVER['PHP_SELF']` échappé via `$selfUrl = dol_escape_htmltag(...)` dans toutes les sorties HTML (href/action) — anti-XSS réfléchi via PATH_INFO (MEDIUM). Les `header('Location')` restent en brut (non-XSS).
