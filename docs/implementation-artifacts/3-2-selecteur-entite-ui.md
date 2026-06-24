---
baseline_commit: 997c777c4406da42180c376bfb2f09d8e8c0b424
---

# Story 3.2 : Sélecteur d'entité dans l'UI

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a utilisateur affecté à plusieurs entités,
I want voir mon entité courante (nom + couleur) en permanence et un sélecteur listant uniquement mes entités autorisées,
so that je sais toujours dans quelle société je travaille (FR9) et je peux basculer sans risque de saisie dans la mauvaise entité.

## Acceptance Criteria

1. Hook handler `core/hookcontrols/actions_multientity.class.php` (`class ActionsMultientity`) ; enregistré dans le descripteur via `module_parts['hooks']` sur le contexte de la barre supérieure (`toprightmenu` ; méthode `printTopRightMenu`).
2. **Indicateur d'entité courante (FR9)** : affichage permanent du **label** de l'entité courante (`$conf->entity`) + sa **pastille couleur** (si définie, format `#RRGGBB` validé en sortie), via `Multientity::getEntity($conf->entity)` / `getEntityLabel`. Échappement systématique des sorties.
3. **Sélecteur** : si l'utilisateur a **plus d'une** entité autorisée (`Multientity::getAllowedEntities($user->id)`), afficher un menu déroulant listant **uniquement** ces entités (label + pastille). Si une seule entité autorisée → afficher l'indicateur seul, sans sélecteur.
4. **Affichage = autorité serveur** : la liste affichée provient de `getAllowedEntities()` (requête serveur), PAS du seul cache session `$_SESSION['multientity_allowed_entities']` (le cache reste un détail de perf non autoritaire). Aucune entité non autorisée ni inactive ne doit apparaître.
5. **Déclenchement du switch** : chaque entrée du sélecteur cible `?switchentity=N` (mécanisme natif lu par `master.inc.php`). ⚠️ **La validation serveur du switch est la story 3.3** — 3.2 ne fait qu'AFFICHER les options autorisées ; elle ne sécurise pas à elle seule le switch (une URL forgée reste possible tant que 3.3/3.4 ne sont pas livrés). À documenter ; ne pas considérer l'epic livrable avant 3.3+3.4+3.5.
6. Le module n'affiche le composant que si activé et si l'utilisateur est authentifié (`$user->id > 0`). Si le module est désactivé → hook absent → aucun affichage (NFR-C2).
7. Sécurité/sortie : `dol_escape_htmltag` sur label/code ; couleur injectée dans `style` validée `/^#[0-9A-Fa-f]{6}$/` (sinon ignorée) ; pas de `$_GET`/`$_POST` direct ; le lien de switch passe par `dol_buildpath`/URL relative échappée.
8. Performance (NFR-P1) : une seule résolution des entités autorisées par page (réutiliser le résultat ; le hook ne doit pas multiplier les requêtes).
9. i18n : clés aux 5 langues (`CurrentEntity`, `SwitchEntity`).
10. Compat 19→23+ : signature de hook `printTopRightMenu($parameters, $object, $action, $hookmanager)` ; `getDolGlobalString` ; retour hook conforme (`return 0;` + `$this->resprints`).

## Tasks / Subtasks

- [x] Task 1 : Hook handler (AC: #1, #2, #3, #4, #6, #7, #8)
  - [x] `core/hookcontrols/actions_multientity.class.php` : `class ActionsMultientity`, méthode `printTopRightMenu($parameters, $object, $action, $hookmanager)` → construit l'indicateur + sélecteur dans `$this->resprints`
  - [x] Résoudre entité courante (`getEntity($conf->entity)`) + entités autorisées (`getAllowedEntities($user->id)`)
- [x] Task 2 : Enregistrement du hook (AC: #1)
  - [x] `module_parts['hooks'] = array('toprightmenu')` dans `modMultiEntity.class.php` (remplace `array()`)
- [x] Task 3 : i18n (AC: #9)
- [x] Task 4 : Vérif (AC tous)
  - [x] `php -l` : OK sur 2 fichiers PHP ; cohérence i18n : 46 clés identiques dans les 5 langues

## Dev Notes

- **Hook barre supérieure** : contexte `toprightmenu`, méthode `printTopRightMenu`. Le HTML est retourné via `$this->resprints` (concaténé par le core), `return 0;`. [Source: CLAUDE.dolibarr.md#5 ; core]
- **Source d'autorité = `getAllowedEntities()`** (3-1) : réutiliser pour l'affichage. Le cache `$_SESSION['multientity_allowed_entities']` est un détail de perf — pour l'affichage on peut s'en servir, mais la liste affichée ne doit jamais inclure une entité non retournée par `getAllowedEntities`. Privilégier l'appel service (1×/page, NFR-P1). [Source: 3-1 ; review 3-1]
- **Le switch n'est pas sécurisé ici** : `master.inc.php` lit nativement `switchentity` SANS validation → la garde serveur (3.3) + garde permanente (3.4) sont indispensables. 3.2 limite l'UI aux entités autorisées mais ne protège pas contre une URL forgée. **Invariant projet : E3 non livrable sans 3.3/3.4/3.5.** [Source: architecture.md#4.2 ; sprint-status NOTE]
- **Couleur** : valider `/^#[0-9A-Fa-f]{6}$/` à l'affichage (anti-injection CSS, cf. correctif 2-2). [Source: 2-2 review C1-1]
- **Activation hooks** : passer `module_parts['hooks']` à `array('toprightmenu')`. Garder `triggers => 1`. La classe hook doit exister (sinon warning Dolibarr) — elle est créée dans cette story. [Source: 1-1 notes]
- **Pattern de référence** : `core/hookcontrols/actions_<nom>.class.php` des autres modules P'tite Tête ; méthode hook retournant 0 + `$this->resprints`.

#### Notes de validation (intégrées)

- **HIGH-1 — `getAllowedEntities` peut être VIDE** (contrat fail-closed de 3-1) : si `array()` vide (erreur SQL / toutes inactives) → ne PAS supposer `{1}`. Le composant n'affiche alors **pas de sélecteur** (et garde l'indicateur en repli) sans erreur PHP. Toujours `is_array()` + `count()` avant usage.
- **HIGH-2 — lien GET simple, AUCUN token en 3.2** : le switch est un `<a href="?switchentity=N">` (GET). Pas de CSRF ici (c'est normal — la validation serveur est 3.3, qui rejettera tout switch non autorisé quelle que soit l'origine). Ne pas ajouter de nonce non vérifié (fausse sécurité) ; l'UI 3.2 restera compatible avec 3.3.
- **MEDIUM-1 — entité courante incohérente** : si `getEntity($conf->entity)` renvoie `null` → label de repli échappé `Entity #N` (ne pas planter). Si `$conf->entity` ∉ `getAllowedEntities` (session incohérente) → afficher l'indicateur mais NE PAS l'inclure dans le sélecteur (la garde 3.4 corrigera la session).
- **MEDIUM-2 — fusion `module_parts`** : modifier UNIQUEMENT la clé `hooks` (`$this->module_parts['hooks'] = array('toprightmenu');`), sans écraser `triggers => 1`.
- **LOW-2 — URL** : utiliser une URL relative simple `?switchentity=N` (échappée), PAS `dol_buildpath` sans 2e argument.
- **LOW-1 — i18n** : `CurrentEntity` = préfixe/tooltip de l'indicateur ; `SwitchEntity` = titre du menu déroulant.

### Project Structure Notes

- `core/hookcontrols/actions_multientity.class.php` (NEW ; dossier `core/hookcontrols/` à créer)
- `core/modules/modMultiEntity.class.php` (UPDATE : `hooks => array('toprightmenu')`)
- `langs/*/multientity.lang` (UPDATE ×5 : +2 clés)

### References

- [Source: docs/planning-artifacts/epics.md#Epic-3] — story 3.2
- [Source: docs/planning-artifacts/prd.md#FR5][#FR9]
- [Source: docs/planning-artifacts/architecture.md#4.2] — flux switch
- [Source: docs/implementation-artifacts/3-1-resolution-entite-login.md] — getAllowedEntities (autorité)
- [Source: CLAUDE.dolibarr.md#5][#11] — hooks, sécurité, compat

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6 (claude-sonnet-4-6) — 2026-06-24

### Debug Log References

Aucun blocage. Implémentation directe conforme aux notes de validation.

### Completion Notes List

- Créé `core/hookcontrols/actions_multientity.class.php` : classe `ActionsMultientity`, méthode `printTopRightMenu` injectant l'indicateur + sélecteur dans `$this->resprints`, retour 0. Garde utilisateur non authentifié. Résolution entité courante + allowed en un seul appel service (NFR-P1). Repli `Entity #N` si `getEntity()` retourne null/-1 (MEDIUM-1). `getAllowedEntities()` vide → indicateur seul, pas de sélecteur (HIGH-1). Couleur validée `/^#[0-9A-Fa-f]{6}$/` avant injection CSS (HIGH-1 review 2-2). URL relative `?switchentity=N` simple sans token (HIGH-2 : la garde est 3.3). Toutes les sorties échappées via `dol_escape_htmltag`.
- Modifié `core/modules/modMultiEntity.class.php` : `hooks => array('toprightmenu')` — `triggers => 1` conservé (MEDIUM-2).
- Ajouté `CurrentEntity` + `SwitchEntity` dans les 5 fichiers `.lang` (fr_FR, en_US, de_DE, es_ES, it_IT).
- `php -l` : aucune erreur syntaxique.
- i18n : 46 clés identiques dans les 5 langues (diff vide).
- Note sécurité documentée dans le PHPDoc : module non livrable sans 3.3/3.4/3.5.

- **Patches review appliqués** : F5 (guard `class_exists('Multientity')` → pas de Fatal Error en install partielle), F8 (réutilisation de `$currentObj` dans la boucle → évite N+1 requêtes, NFR-P1), F3 (`dol_escape_htmltag` sur le href du switch). **Review : aucune fuite d'entité non autorisée, aucun XSS (label échappé, couleur regex-validée).**

### File List

- core/hookcontrols/actions_multientity.class.php (NEW)
- core/modules/modMultiEntity.class.php (MODIFIÉ)
- langs/fr_FR/multientity.lang (MODIFIÉ)
- langs/en_US/multientity.lang (MODIFIÉ)
- langs/de_DE/multientity.lang (MODIFIÉ)
- langs/es_ES/multientity.lang (MODIFIÉ)
- langs/it_IT/multientity.lang (MODIFIÉ)

## Change Log

- 2026-06-24 : Story 3.2 implémentée — hook `toprightmenu` + indicateur entité courante + sélecteur multi-entité UI.
