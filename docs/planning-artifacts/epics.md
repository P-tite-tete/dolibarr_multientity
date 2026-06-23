# Epics & Stories — modMultiEntity (Multi-Entité)

Date : 2026-06-23 · Statut : Draft · Module `numero = 351007`

Découpage en 5 epics. Ordre conseillé : E1 → E2 → E3 → E4 (E5 transverse, en continu). La **sécurité d'isolation (E3)** est le cœur : ne pas livrer sans ses tests.

---

## Epic 1 — Fondations module & schéma

Objectif : module installable, tables créées, descripteur conforme P'tite Tête.

- **1.1 Descripteur `modMultiEntity`** : `numero=351007`, famille, droits (`read`, `manage`), `$this->const`, `$this->tables` (les 2 tables), hooks déclarés. Activation/désactivation propres.
- **1.2 Schéma SQL idempotent** : `llx_multientity_entity` + `llx_multientity_user_entity` (DDL fresh + migration `information_schema`). Enregistrement auto de l'entité `1` à l'activation.
- **1.3 Squelette i18n + lib** : `langs/*/multientity.lang` (5 langues, clés de base), `lib/multientity.lib.php` (`multientityAdminPrepareHead`).
- **AC** : module s'active/désactive sans erreur sur Dolibarr 19/21/23 ; tables créées idempotentes ; `php -l` OK.

## Epic 2 — Gestion des entités & affectation utilisateurs

Objectif : un super-admin crée des entités et y affecte des utilisateurs.

- **2.1 Service `Multientity`** : `listEntities()`, `createEntity()`, `setActive()`, `getEntityLabel()`. Aucune suppression si données rattachées (désactivation seulement).
- **2.2 Page `admin/entities.php`** : CRUD entités (label, code, couleur, actif), CSRF, `$user->admin`, sorties échappées.
- **2.3 Initialisation d'entité** : à la création, init des constantes société de l'entité N (`dolibarr_set_const(..., entity=N)`) — nom, adresse, devise, pays.
- **2.4 Affectation user ↔ entités** (`admin/user_entities.php`) : associer un user à N entités + entité par défaut ; table `llx_multientity_user_entity`.
- **AC** : créer 2 entités, affecter un user aux deux dont une par défaut ; données persistées ; couverture i18n.

## Epic 3 — Switch d'entité sécurisé (CŒUR)

Objectif : basculer d'entité en **isolation stricte** garantie. **Ne pas livrer sans les tests de sécurité.**

- **3.1 Résolution entité au login** : hook post-login → pose `$_SESSION['dol_entity']` = entité par défaut autorisée (sinon 1). Cache session des entités autorisées (NFR-P1).
- **3.2 Sélecteur d'entité UI** : composant barre supérieure (hook `printTopRightMenu`/équivalent) listant **uniquement** les entités autorisées ; indicateur d'entité courante (nom + couleur, FR9).
- **3.3 Contrôle d'accès serveur (NFR-S2)** : interception du `switchentity` → valider `N ∈ autorisées(user)` côté serveur, sinon refus + `LOG_WARNING` (NFR-S3) + rester sur entité courante. Tokenisation CSRF.
- **3.4 Garde permanente (défense en profondeur)** : revalider à chaque page que `$conf->entity` est autorisée ; sinon forcer défaut + log.
- **3.5 Tests de sécurité d'isolation (NFR-S1)** : URL forgée `?switchentity=`, session forgée, user désaffecté en cours de session, accès API REST par entité. **0 fuite** attendu.
- **AC** : user A↔{1,2}, user B↔{1} ; B tentant entité 2 (UI ou URL) → refus+log ; A bascule librement ; données des entités strictement isolées.

## Epic 4 — Intégration suite P'tite Tête & non-régression

Objectif : valider l'écosystème en multi-entité maison.

- **4.1 Validation Doli2Shop par entité** : 2 entités = 2 boutiques Shopify, credentials/mappings/licence indépendants ; sync OK sans interférence.
- **4.2 Non-régression compta/facturation** : numérotation, factures, grand livre, TVA isolés par entité (tests sur 2 entités).
- **4.3 Désactivation propre (NFR-C2)** : module off → retour mono-entité `entity=1` sans donnée bloquante.
- **AC** : scénario complet 2 sociétés/2 boutiques fonctionnel ; désactivation réversible.

## Epic 5 — Qualité, doc, packaging (transverse)

- **5.1 Tests unitaires** (service entités, contrôle d'accès) + tests sécurité (E3.5).
- **5.2 Compat multi-versions** : fumée Dolibarr 19/21/23 (`master.inc.php`/`getEntity()`).
- **5.3 Docs** : README (2 voies multi-boutique), guide install/usage, mention isolation stricte (pas de partage).
- **5.4 i18n complète 5 langues.**
- **5.5 Packaging** : `build/` makepack, ZIP `dist/`, checklist DoliStore (si publication envisagée), licence GPL v3+.

## Epic 6 — API REST MultiEntity (configuration & entités)

Objectif : exposer des **endpoints REST dédiés** au module pour lire/créer la configuration et les entités (le core REST n'expose ni nos tables ni nos constantes). Réutilisable par intégrations externes et pour la démo/présentation. **Dépend de E2 (service `Multientity`) et de E3 (contrôle d'accès)** : l'API réutilise la même validation serveur — jamais de logique d'isolation dupliquée/affaiblie.

- **6.1 Classe API du module** : `class/api_multientity.class.php` étendant `DolibarrApi`, auto-découverte Dolibarr (endpoints sous `/multientity/...`). Auth par token API standard, `hasRight('multientity', …)`.
- **6.2 Lecture entités** : `GET /multientity/entities` → **uniquement les entités autorisées de l'utilisateur du token** (isolation stricte, NFR-S1). `GET /multientity/entities/{id}` avec contrôle d'accès.
- **6.3 Création/maj entité** : `POST /multientity/entities` (droit `manage`), `PUT /multientity/entities/{id}` ; validations + init constantes société (cf. 2.3).
- **6.4 Configuration module** : `GET /multientity/config` (lecture des constantes `MULTIENTITY_*`), `PUT /multientity/config` (droit `manage`).
- **AC** : token requis ; un utilisateur ne lit/agit QUE sur ses entités autorisées (tentative cross-entité → 403 + log, NFR-S3) ; aucune fuite inter-entité par l'API (vecteur déjà listé architecture §5) ; doc Swagger à jour.

---

## Dépendances & risques (rappel)

- E3 dépend de E1/E2. E4 dépend de E3. **E6 (API) dépend de E2 + E3** (réutilise service + contrôle d'accès, ne ré-implémente pas l'isolation).
- Risque central : **sécurité d'isolation** (E3.3/3.4/3.5) — traiter en priorité, ne jamais livrer partiellement.
- Risque compat : évolutions core entre versions (E5.2).
- Décision produit en suspens : **partage de référentiels** = hors périmètre ; si exigé par un client → rouvrir l'arbitrage (Multicompany payant vs extension).
