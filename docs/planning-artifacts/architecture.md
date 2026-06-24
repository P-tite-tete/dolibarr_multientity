# Architecture — modMultiEntity (Multi-Entité)

Date : 2026-06-23 · Statut : Draft · Module `numero = 351007` (plage P'tite Tête 351000-351099)

## 1. Principe directeur

**Réutiliser le multi-entité natif du core, n'ajouter QUE l'orchestration.** Vérifié sur Dolibarr 23 + BDD prod (MariaDB 11.8) :

| Capacité | Fourni par | Détail |
|---|---|---|
| Stockage isolé par entité | **Core (natif)** | colonne `entity` sur toutes les tables-objets, **compta incluse** (`llx_accounting_bookkeeping`, `accounting_journal`, `accounting_account`, `facture`, `societe`, `paiement`, `bank_account`…) |
| Filtrage des requêtes | **Core (natif)** | `getEntity($element)` → sans objet `$mc`, retourne `(int)$conf->entity` (isolation stricte) |
| Positionnement entité courante | **Core (natif)** | `master.inc.php` lit `$_SESSION['dol_entity']`, `GETPOST('switchentity')`, `$_ENV['dol_entity']`, constante `DOLENTITY` |
| **Table des entités** | **modMultiEntity** | le core n'a PAS de table d'entités |
| **Création/gestion d'entités** | **modMultiEntity** | UI admin |
| **Affectation user ↔ entités** | **modMultiEntity** | table de liaison |
| **Switch sécurisé + login par défaut** | **modMultiEntity** | hook + contrôle d'accès |

Conséquence : **on ne touche jamais au core**, on ne surcharge pas `getEntity()` (on laisse le comportement strict natif). Le module est une couche fine.

## 2. Schéma de données

### `llx_multientity_entity` — les entités gérées
```
rowid        integer AUTO_INCREMENT PRIMARY KEY,
entity_id    integer NOT NULL,        -- la valeur de $conf->entity représentée (1, 2, 3…)
label        varchar(128) NOT NULL,
code         varchar(32) DEFAULT NULL,-- code court (ex. SCI, SARL1)
color        varchar(7) DEFAULT NULL, -- pastille UI (#RRGGBB)
active       tinyint NOT NULL DEFAULT 1,
date_creation datetime DEFAULT NULL,
tms          timestamp
-- UNIQUE KEY uk_multientity_entity (entity_id)
```
> `entity_id` = la valeur réelle utilisée par le core dans la colonne `entity` des tables métier. Le module ne « crée » pas une entité au sens core (pas de table core) : il **référence** des valeurs d'entité et fournit l'orchestration autour.

### `llx_multientity_user_entity` — affectation utilisateurs
```
rowid        integer AUTO_INCREMENT PRIMARY KEY,
fk_user      integer NOT NULL,
entity_id    integer NOT NULL,
is_default   tinyint NOT NULL DEFAULT 0,
tms          timestamp
-- UNIQUE KEY uk_multientity_user_entity (fk_user, entity_id)
-- INDEX idx_multientity_user (fk_user)
```

> Migrations idempotentes via `information_schema` (jamais `IF NOT EXISTS`).
> ⚠️ Ces deux tables n'ont **pas** de colonne `entity` : ce sont des tables **transverses** (méta-gestion des entités), volontairement globales (entity_id y est une donnée, pas un filtre de tenant). À documenter pour ne pas déclencher l'alerte « table sans entity ».

## 3. Composants

```
core/modules/modMultiEntity.class.php      Descripteur (numero 351007, tables, droits, const, hooks)
sql/llx_multientity_entity.sql             DDL table entités
sql/llx_multientity_user_entity.sql        DDL table affectations
sql/update_*.sql                           Migrations idempotentes
class/multientity.class.php                Service : list/create/setActive, entités autorisées d'un user
class/multientityaccess.class.php          Contrôle d'accès (résolution entité courante autorisée, cache session)
core/hookcontrols/actions_multientity.class.php  Hook UI (sélecteur d'entité) + interception switch
admin/setup.php                            Config module
admin/entities.php                         CRUD entités
admin/user_entities.php                    Affectation user ↔ entités
class/api_multientity.class.php            API REST (Epic 6) — étend DolibarrApi, endpoints /multientity/* ; réutilise multientityaccess (zéro isolation dupliquée)
langs/{fr_FR,en_US,de_DE,es_ES,it_IT}/multientity.lang
lib/multientity.lib.php                    Helpers (prepareHead, getCurrentEntityLabel, …)
test/unit/…                                Tests (isolation/sécurité en priorité)
```

## 4. Flux clés

### 4.1 Login → entité par défaut
1. Authentification standard Dolibarr.
2. Hook `afterLogin` (ou équivalent) : lire l'entité par défaut de l'utilisateur (`llx_multientity_user_entity.is_default`), poser `$_SESSION['dol_entity']`.
3. `master.inc.php` (requête suivante) applique `$conf->entity`.

### 4.2 Switch d'entité (cœur sécurité)
> ⚠️ **Fait core vérifié (Dolibarr 23, master.inc.php:286)** : `switchentity`/`entity` n'est lu par le core **QUE pendant `loginfunction`** (au login). **Hors login, le core prend `$_SESSION["dol_entity"]`** et ignore `switchentity`. → Le switch en cours de session **doit être implémenté par le module** (le core ne le fait pas).
1. UI : sélecteur listant **uniquement** les entités autorisées du user (source = `getAllowedEntities`).
2. Soumission `?switchentity=N` (lien GET en 3.2 ; tokenisation CSRF ajoutée en 3.3).
3. **Point d'accroche = hook `updateSession` (contexte `main`)** exécuté sur chaque page (main.inc.php:1015), tôt, `$user` authentifié, `$conf->entity` déjà résolu depuis la session, AVANT le chargement des données de page.
4. **Contrôle serveur (NFR-S2)** : à réception de `switchentity=N`, valider `N ∈ getAllowedEntities(user)` ; si OK → `$_SESSION['dol_entity'] = N` + `$conf->entity = N` + redirect (PRG) ; sinon refus + `dol_syslog(LOG_WARNING)` (NFR-S3) + rester sur l'entité courante.

### 4.3 Garde permanente
- Même hook `updateSession` (défense en profondeur, sur chaque page) : revalider que `$conf->entity` ∈ `getAllowedEntities(user)`. Si KO (session forgée / entité désactivée / désaffectation en cours de session) → forcer une entité autorisée (défaut, ou rien si scope vide en fail-closed) + `dol_syslog(LOG_WARNING)`. Couvre aussi le cas C1-1 (user en fallback `{1}` non affecté à 1 → refus d'accès aux données de 1).

## 5. Sécurité (exigence centrale)

- **Le risque #1 = fuite inter-société.** Toute la valeur du module repose sur NFR-S1/S2/S3.
- `getEntity()` strict natif garantit l'isolation des **données** tant que `$conf->entity` est correct → tout le contrôle se concentre sur « quelle valeur d'entité l'utilisateur a le droit de poser ».
- Vecteurs à couvrir par tests : UI sélecteur, URL forgée `?switchentity=`, réutilisation de session, appels API REST (`entity` côté token API), utilisateur désaffecté d'une entité en cours de session.
- Super-admin (`$user->admin` + droit dédié) seul habilité à gérer entités et affectations.

## 6. Intégration Doli2Shop & suite P'tite Tête

- Doli2Shop filtre déjà tout par `$conf->entity` et stocke ses credentials/mappings par entité → **aucune modification requise** côté Doli2Shop pour le cas « sociétés distinctes ». 1 entité = 1 société = 1 boutique = 1 licence.
- Distinction à garder claire (cf. [[reference-multientite-natif-vs-multicompany]] côté Doli2Shop) :
  - **Même société, N boutiques** → multi-store interne Doli2Shop (entity=1), PAS ce module.
  - **Sociétés distinctes** → ce module (multi-entité) + Doli2Shop par entité.

## 7. Compatibilité & non-régression

- N'utiliser que `getEntity()`, `$conf->entity`, hooks publics, `$_SESSION['dol_entity']`. **Zéro patch core.**
- Désactivation du module → `$_SESSION['dol_entity']` non posée → `$conf->entity=1` → mono-entité propre (NFR-C2).
- Valider la non-interférence avec les modules compta/facturation en isolation stricte (pas de partage).
- Point de vigilance versions : surveiller l'évolution de `master.inc.php`/`getEntity()` entre 19 et 23+ (tests de fumée par version cible).

## 8. Hors périmètre (rappel)

Partage de référentiels (`*_perentity` façon Multicompany), numérotation cross-entité, consolidation, transferts inter-entités, droits cross-entité fins. Si un besoin de partage émerge → décision produit dédiée (réévaluer Multicompany payant vs extension du module).
