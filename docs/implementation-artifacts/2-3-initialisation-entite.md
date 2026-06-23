# Story 2.3 : Initialisation des constantes société par entité

---
baseline_commit: c5cfa8d7cbf551b8dde1db2e6d2ef92ae9eb1988
---

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a super-administrateur créant une nouvelle entité (société),
I want renseigner les informations société de base (nom, adresse, pays, devise) propres à cette entité,
so that chaque entité dispose dès sa création de sa propre identité émettrice, isolée des autres (FR3).

## Acceptance Criteria

1. Nouvelle méthode `Multientity::initEntityConstants($entity_id, array $params)` : pose les constantes société pour l'entité N **uniquement** via `dolibarr_set_const($db, $name, $value, 'chaine', 0, '', (int) $entity_id)`.
2. Constantes gérées : `MAIN_INFO_SOCIETE_NOM`, `MAIN_INFO_SOCIETE_ADDRESS`, `MAIN_INFO_SOCIETE_ZIP`, `MAIN_INFO_SOCIETE_TOWN`, `MAIN_INFO_SOCIETE_COUNTRY` (format Dolibarr `id:CODE:Label`, ex. `1:FR:France`), `MAIN_MONNAIE` (ex. `EUR`).
3. Seuls les paramètres **fournis et non vides** sont posés (pas d'écrasement par du vide). `entity_id` doit exister (`getEntity()`), sinon retour `<0` + log.
4. **Isolation** : l'écriture cible exclusivement `entity = $entity_id` (jamais `$conf->entity` ni entité 0/courante). Vérifier que l'écriture ne fuit pas vers une autre entité.
5. Le formulaire de création d'entité (`admin/entities.php`) est étendu avec des champs **optionnels** : nom société, adresse, CP, ville, pays (`$form->select_country`), devise (`$form->selectCurrency` ou liste). Après `createEntity()` réussi, `initEntityConstants()` est appelée pour la nouvelle entité. **Un échec de `initEntityConstants()` n'annule PAS la création** (l'entité est déjà commitée par `createEntity`) : afficher un warning et laisser l'admin compléter via l'action `company`.
6. Une section « Société » par entité, **intégrée à `admin/entities.php`** (même page, même PRG/token — pas de sous-page séparée), permet de **consulter/mettre à jour** ces constantes : action `?action=company&entity_id=N` → formulaire pré-rempli lisant les constantes de l'entité N via `Multientity::getEntityConstants($entity_id)` (PAS `getDolGlobalString`, qui lit l'entité courante).
7. `getEntityConstants($entity_id)` : lit les constantes société de l'entité N depuis `llx_const WHERE entity = N` (SELECT filtré, `MAIN_DB_PREFIX`, escape). Retourne un tableau associatif (clé→valeur).
8. Sécurité/conventions : `$user->admin`, **CSRF vérifié aussi sur l'action `company`** (l'ajouter à la liste `create|update|setactive|company` du guard token), `GETPOST` typés, échappement sorties, `dol_syslog`. **Pays** : valider le `country_id` POST par un SELECT `llx_c_country WHERE rowid = (int)$country_id` ; la valeur `MAIN_INFO_SOCIETE_COUNTRY` (`rowid:CODE:Label`) est reconstruite **depuis la base**, jamais depuis le POST direct ; rejet si rowid inconnu. **Devise** : valider `MAIN_MONNAIE` POST contre `llx_currency WHERE active = 1` (ou `getValidCurrencies()`) ; rejet si non listée.
9. i18n : clés ajoutées aux 5 langues (`CompanyInfo`, `CompanyName`, `CompanyAddress`, `CompanyZip`, `CompanyTown`, `CompanyCountry`, `CompanyCurrency`, `CompanyInfoSaved`).
10. Compat 19→23+ : `dolibarr_set_const`/`dolibarr_get_const` avec param entity, `$form->select_country`, pas de constante en dur.

## Tasks / Subtasks

- [x] Task 1 : Méthodes service (AC: #1, #2, #3, #4, #7)
  - [x] `initEntityConstants($entity_id, array $params)` (dolibarr_set_const ciblé entité N, seulement non vides, vérif existence)
  - [x] `getEntityConstants($entity_id)` (SELECT llx_const WHERE entity=N pour les clés société)
- [x] Task 2 : Intégration création (AC: #5)
  - [x] Champs société optionnels dans le formulaire de création ; appel `initEntityConstants` après `createEntity`
- [x] Task 3 : Édition société par entité (AC: #6)
  - [x] Action `company` + formulaire pré-rempli via `getEntityConstants()`
- [x] Task 4 : i18n (AC: #9) + Vérif (php -l, cohérence clés, test démo : créer entité 2 avec société, vérifier `llx_const` entity=2)

## Dev Notes

- **`dolibarr_set_const` signature** : `dolibarr_set_const($db, $name, $value, $type='chaine', $visible=0, $note='', $entity=1)`. Le 7e argument `$entity` est la clé de l'isolation — toujours `(int) $entity_id`, jamais `$conf->entity`. [Source: core Dolibarr]
- **Lecture par entité** : `getDolGlobalString()` lit l'entité **courante** → inutilisable pour lire l'entité N quand on est dans l'entité courante. D'où `getEntityConstants($entity_id)` qui fait un `SELECT value FROM llx_const WHERE name IN (...) AND entity = (int) $entity_id`. [Source: architecture §1 — entity natif sur llx_const]
- **Pays** : Dolibarr stocke `MAIN_INFO_SOCIETE_COUNTRY` au format `rowid:CODE:Label`. Utiliser `$form->select_country($selected, 'country_id')` qui retourne l'id ; reconstruire la valeur via la table `llx_c_country` (ou helper core `getCountry`). Valider l'id contre `llx_c_country`. [Source: core]
- **Réutiliser le service** : aucune écriture `llx_const` directe hors `initEntityConstants` ; aucune requête SQL dans la page. [Source: 2-1, 2-2]
- **Isolation (critique)** : c'est la première story qui écrit des données rattachées à une entité ≠ courante → tester explicitement qu'aucune constante ne se pose sur l'entité courante par erreur. [Source: prd NFR-S1]
- **Pattern page** : réutiliser la structure de `admin/entities.php` (actions + PRG + token). L'action `company` y est intégrée (pas de fichier séparé).

#### Notes de validation (intégrées)

- **Pays (anti-injection)** : `country_id` POST validé par `SELECT rowid, code, label FROM llx_c_country WHERE rowid = (int)$country_id` ; `MAIN_INFO_SOCIETE_COUNTRY` = `rowid.':'.code.':'.label` reconstruit depuis ce SELECT. Rejet si non trouvé.
- **Devise** : valider `MAIN_MONNAIE` contre `SELECT code_iso FROM llx_currency WHERE active = 1` (ou `getValidCurrencies()`). Rejet sinon.
- **CSRF** : guard token étendu à `create|update|setactive|company`.
- **Échec init non bloquant** : `initEntityConstants` après `createEntity` (déjà commit) → warning si KO, jamais de rollback de l'entité.

### Project Structure Notes

- `class/multientity.class.php` (UPDATE : +initEntityConstants, +getEntityConstants)
- `admin/entities.php` (UPDATE : champs société à la création + action `company`)
- `langs/*/multientity.lang` (UPDATE ×5)

### References

- [Source: docs/planning-artifacts/epics.md#Epic-2] — story 2.3
- [Source: docs/planning-artifacts/prd.md#FR3] — init constantes société à la création
- [Source: docs/planning-artifacts/architecture.md#1] — `entity` natif sur la compta/const, isolation stricte
- [Source: docs/implementation-artifacts/2-1-service-multientity.md][2-2-page-admin-entities.md] — service & page réutilisés
- [Source: CLAUDE.dolibarr.md#4][#11] — SQL, sécurité, compat

## Dev Agent Record

### Agent Model Used

Sonnet (validate + dev + review 3-layer), coordination Opus. 2026-06-23.

### Debug Log References

`php -l` OK (multientity.class.php, entities.php) ; 35 clés i18n alignées sur 5 langues.

### Completion Notes List

- 10 AC implémentés (validés + reviewés). **Isolation confirmée saine par la review** : `initEntityConstants`/`getEntityConstants` ciblent exclusivement `entity = (int)$entity_id`, jamais `$conf->entity` — zéro fuite inter-entité.
- Patches review appliqués : **F5** (redirect `company` conserve `?action=company&entity_id=N` — AC#6), **E2** (guard `!empty($params)` avant init), **E1** (feedback `EntityNotFound` si entity_id absent/inconnu en GET), **E5** (warning `InvalidCountrySelection` si pays invalide + clé ajoutée aux 5 langues).
- Finding rejeté : **F2** (escape explicite avant `dolibarr_set_const`) — la lib core échappe déjà en interne ; pré-escaper provoquerait un double-échappement corrompant les données.
- Pays validé via `llx_c_country` (valeur reconstruite depuis la base), devise via `llx_currency WHERE active=1` — anti-injection.

### File List

- class/multientity.class.php (UPDATE : +initEntityConstants, +getEntityConstants)
- admin/entities.php (UPDATE : champs société création + action company + formulaire pré-rempli)
- langs/{fr_FR,en_US,de_DE,es_ES,it_IT}/multientity.lang (UPDATE : +9 clés)
