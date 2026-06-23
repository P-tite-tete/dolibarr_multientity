# Story 3.1 : Résolution de l'entité au login

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a utilisateur multi-entité authentifié,
I want que ma session s'ouvre automatiquement sur mon entité par défaut autorisée,
so that je travaille d'emblée dans la bonne société sans manipulation, et jamais dans une entité non autorisée (FR6, base de l'isolation E3).

## Acceptance Criteria

1. Un trigger `core/triggers/interface_99_modMultiEntity_LoginEntity.class.php` (étend `DolibarrTriggers`, `runTrigger`) réagit à l'action **`USER_LOGIN`**.
2. Le descripteur active les triggers : `module_parts['triggers'] = 1` (révision prévue de l'AC#9 de la story 1.1 — était 0 en Epic 1).
3. **Règle d'autorité centralisée** — nouvelle méthode service `Multientity::getAllowedEntities($fk_user)` (source unique réutilisée par 3.3/3.4) qui retourne la liste **autoritaire** des entités accessibles :
   - `affectations_actives` = `getUserEntities($fk_user)` ∩ `listEntities(true)` (croisement autorisées ∩ actives — corrige F-12).
   - **Si `affectations_actives` non vide** → c'est la liste autorisée (l'entité 1 n'y figure QUE si explicitement affectée — isolation stricte, pas d'octroi implicite).
   - **Si le user a 0 affectation** → liste = `{1}` (fallback mono-entité : entité principale, rétrocompat install non configurée) — `LOG_INFO`.
   - **Si le user a des affectations mais TOUTES inactives** (intersection vide) → liste = `{1}` + **`LOG_WARNING` sécurité** (situation anormale).
4. Au login, l'entité d'ouverture = `getDefaultEntity($fk_user)` **si ∈ getAllowedEntities** ; sinon la **première** de `getAllowedEntities` (ordre `entity_id` croissant) ; la liste n'étant jamais vide (au pire `{1}`), une entité est toujours résolue.
5. Le trigger pose `$_SESSION['dol_entity']` = entité retenue. `master.inc.php` (requête suivante) applique `$conf->entity` (comportement natif, aucun patch core).
6. **Garde d'entrée du trigger (F4)** : ne traiter que si `$action === 'USER_LOGIN'` ET `$object instanceof User` ET `(int) $object->id > 0` ; sinon `return 0` immédiat (aucun effet).
7. **Sécurité (NFR-S1)** : ne jamais poser une entité ∉ `getAllowedEntities`. Le défaut incohérent est ignoré au profit de la 1re autorisée (AC#4) ; tout écart (défaut hors autorisées, toutes entités inactives) est journalisé `LOG_WARNING`.
8. **Cache session (NFR-P1) — NON AUTORITAIRE** : la liste autorisée est mise en cache (`$_SESSION['multientity_allowed_entities']` = tableau d'`entity_id`) **uniquement pour la performance**. ⚠️ Ce cache n'est **JAMAIS** une source d'autorité : les stories 3.3 (contrôle serveur) et 3.4 (garde) DOIVENT revalider via `Multientity::getAllowedEntities()` (requête serveur) — ne jamais autoriser un switch sur la seule base de ce tableau (risque de session forgée). Recalculé à chaque login.
9. Journalisation (NFR-S3) : `LOG_INFO` de l'entité d'ouverture résolue (`user`, `entity_id`) ; idéalement inclure l'IP pour l'audit.
10. Robustesse : si le module est désactivé, le trigger n'existe plus → `$_SESSION['dol_entity']` non posée → `$conf->entity=1` natif (NFR-C2, pas de régression). Si le service échoue (exception/retour erreur), fallback silencieux sur entité 1 + `LOG_WARNING`.
11. Conventions/compat : `dol_syslog(__METHOD__...)` ; pas d'accès `$conf->global` direct ; mécanisme trigger validé sur Dolibarr **19 ET 23** (timing de lecture de `$_SESSION['dol_entity']` par `master.inc.php` à re-vérifier sur la cible min 19).

## Tasks / Subtasks

- [ ] Task 1 : Méthode service `getAllowedEntities($fk_user)` (AC: #3) — **règle d'autorité centralisée**
  - [ ] Croisement `getUserEntities ∩ listEntities(true)` ; fallback `{1}` si 0 affectation (LOG_INFO) ou si toutes inactives (LOG_WARNING) ; retourne un tableau d'`entity_id` (≥1 élément)
- [ ] Task 2 : Trigger USER_LOGIN (AC: #1, #4, #5, #6, #7, #9, #10)
  - [ ] `core/triggers/interface_99_modMultiEntity_LoginEntity.class.php` : garde d'entrée (#6) → `getAllowedEntities` → résolution défaut (#4) → pose `$_SESSION['dol_entity']` ; try/catch fallback 1 (#10)
- [ ] Task 3 : Activer les triggers (AC: #2) — **bloquant**
  - [ ] `module_parts['triggers'] = 1` dans `modMultiEntity.class.php` (sinon le trigger n'est pas chargé par Dolibarr)
- [ ] Task 4 : Cache session non autoritaire (AC: #8)
  - [ ] Poser `$_SESSION['multientity_allowed_entities']` au login (perf uniquement)
- [ ] Task 5 : Vérif (AC tous)
  - [ ] `php -l` ; test démo : user A↔{1,2} défaut 2 → ouvre sur 2 ; user affecté {2} seulement → ouvre sur 2 (PAS 1) ; user sans affectation → entité 1 ; entité du défaut désactivée → 1re autorisée + log ; désactivation module → retour entity=1

## Dev Notes

- **Mécanisme trigger** : `interface_99_mod<Nom>_<X>.class.php` étendant `DolibarrTriggers`, méthode `runTrigger($action, $object, $user, $langs, $conf)` ; réagir uniquement à `$action === 'USER_LOGIN'`. Le `$object` est l'utilisateur connecté. [Source: CLAUDE.dolibarr.md#5]
- **Pourquoi un trigger (et pas un hook)** : `USER_LOGIN` est le point natif post-authentification ; poser `$_SESSION['dol_entity']` à ce moment → appliqué par `master.inc.php` à la requête suivante (redirection post-login). [Source: architecture.md#4.1]
- **Service = source unique** : la résolution des entités autorisées passe par `Multientity` (réutilise `getUserEntities`/`getDefaultEntity` de 2-1, qui filtrent déjà le user actif). **Issue F-12 (retro E2)** : `getUserEntities` ne filtre PAS l'entité active → cette story DOIT croiser avec `listEntities(true)` pour ne retenir que les entités **actives**. [Source: epic-2-retro-2026-06-23.md ; 2-1]
- **Isolation** : le trigger ne fait que POSER une valeur d'entité validée ; le filtrage des données reste natif (`getEntity()` strict). La validation à chaque switch/page est traitée en 3.3/3.4. Ici on garantit que la valeur d'ouverture est légitime. [Source: architecture.md#5]
- **Descripteur triggers=1** : nécessaire pour que Dolibarr charge le trigger. Mettre à jour le commentaire de l'AC#9 de 1.1 (hooks/triggers). [Source: 1-1 notes de validation]
- **Désactivation propre** : ne rien persister d'irréversible ; tout passe par la session (NFR-C2). [Source: prd NFR-C2]
- **Pattern de référence** : triggers des autres modules P'tite Tête (`core/triggers/interface_99_mod*`), structure `runTrigger` retour `>=0`.

### Project Structure Notes

- `core/triggers/interface_99_modMultiEntity_LoginEntity.class.php` (NEW)
- `class/multientity.class.php` (UPDATE : +`getAllowedEntities($fk_user)` — règle d'autorité centralisée, réutilisée par 3.3/3.4 ; **source unique de l'isolation**)
- `core/modules/modMultiEntity.class.php` (UPDATE : `triggers => 1`)
- Le dossier `core/triggers/` n'existe pas encore → à créer.

### References

- [Source: docs/planning-artifacts/epics.md#Epic-3] — story 3.1
- [Source: docs/planning-artifacts/prd.md#FR6][#NFR-S1][#NFR-P1][#NFR-C2]
- [Source: docs/planning-artifacts/architecture.md#4.1][#5] — flux login, sécurité
- [Source: docs/implementation-artifacts/2-1-service-multientity.md] — getUserEntities/getDefaultEntity
- [Source: docs/implementation-artifacts/epic-2-retro-2026-06-23.md] — issue F-12 (entité active)
- [Source: CLAUDE.dolibarr.md#5][#11] — triggers, compat

## Dev Agent Record

### Agent Model Used

Sonnet (validate + dev + review 3-layer), coordination Opus. 2026-06-23.

### Debug Log References

`php -l` OK (trigger, multientity.class.php, modMultiEntity.class.php).

### Completion Notes List

- 11 AC implémentés (validés + reviewés). **Verdict review : aucun chemin actif d'escalade d'entité** — le trigger ne pose jamais une entité hors `getAllowedEntities()`.
- Règle d'autorité centralisée `getAllowedEntities()` (source unique réutilisée par 3.3/3.4) : croisement `getUserEntities ∩ listEntities(true)` (corrige F-12), fallback `{1}` documenté.
- Patches review appliqués : **EC6** (log WARNING systématique sur défaut hors autorisées, même =1), **C1-2** (`REMOTE_ADDR` validé `filter_var` anti log-injection), **C1-5** (trace impersonation `par_user`), **C1-3** (log `LOG_ERR` sur erreur SQL de `getDefaultEntity`), **C1-4** (type int du cache session documenté).
- **C1-1 (HIGH, reporté en 3.3)** : fallback `{1}` quand toutes les entités d'un user sont inactives → la garde d'accès aux données (3.3) DOIT refuser l'accès aux données de l'entité 1 si le user n'y est pas explicitement affecté. Documenté in-code + action item E3.

- **Correctif sécurité post-review pushed (FAIL-OPEN → FAIL-CLOSED)** : suite à la review automatique des commits, les fallbacks `{1}` sur erreur ont été supprimés. Désormais : erreur SQL (`getUserEntities`/`listEntities`) OU toutes entités du user inactives → `getAllowedEntities` retourne `array()` **vide** ; le trigger NE POSE AUCUNE entité (`unset $_SESSION['dol_entity']`) + `LOG_ERR`. `getDefaultEntity` renvoie sentinel `0` (pas `1`) sur erreur/absence. Exception → session non posée (pas `=1`) + `LOG_ERR`. Seul `{1}` conservé = user **sans aucune affectation** (rétrocompat mono-entité, décision produit documentée). Plus aucun octroi d'entité indu via le login.

### File List

- core/triggers/interface_99_modMultiEntity_LoginEntity.class.php (NEW)
- class/multientity.class.php (UPDATE : +getAllowedEntities, log getDefaultEntity)
- core/modules/modMultiEntity.class.php (UPDATE : triggers => 1)
