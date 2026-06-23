# PRD — modMultiEntity (Multi-Entité)

Date : 2026-06-23 · Statut : Draft · Cible Dolibarr : 19.0 → 23.0+ · PHP 7.4–8.4 · MySQL 5.7+/MariaDB 10.3+

## 1. Contexte & objectif

Fournir la gestion multi-entité (multi-société) en **isolation stricte** sur une instance Dolibarr unique, sans Multicompany. Le socle (`entity` natif partout, `getEntity()`, lecture de `$_SESSION['dol_entity']` par `master.inc.php`) existe déjà dans le core ; le module ajoute uniquement l'**orchestration** (entités, affectation users, switch sécurisé).

Voir analyse technique vérifiée : product-brief.md §1 et architecture.md.

## 2. Exigences fonctionnelles (FR)

- **FR1 — Table des entités.** Le module crée `llx_multientity_entity` (id, label, code, statut actif, date création, …) car le core n'a pas de table d'entités. L'entité `1` (existante) est enregistrée automatiquement à l'activation.
- **FR2 — CRUD entités.** Un super-administrateur peut créer, renommer, activer/désactiver une entité depuis une page admin. La suppression est interdite si des données métier y sont rattachées (sécurité comptable) — désactivation seulement.
- **FR3 — Initialisation d'entité.** À la création d'une entité, le module initialise les constantes de base (nom société émettrice, adresse, devise, pays) de cette entité (`llx_const.entity = N`).
- **FR4 — Affectation utilisateur ↔ entités.** Un super-admin associe chaque utilisateur à une ou plusieurs entités autorisées et définit son entité par défaut (`llx_multientity_user_entity`).
- **FR5 — Switch d'entité.** Un utilisateur authentifié peut basculer entre **ses entités autorisées** via un sélecteur (barre supérieure). Le switch positionne `$_SESSION['dol_entity']` (déjà lu par `master.inc.php`).
- **FR6 — Entité par défaut au login.** À la connexion, `$_SESSION['dol_entity']` est positionnée sur l'entité par défaut de l'utilisateur (ou `1` à défaut).
- **FR7 — Contrôle d'accès strict (sécurité).** Toute tentative de positionner une entité non autorisée pour l'utilisateur est refusée et journalisée ; `$conf->entity` retombe sur une entité autorisée.
- **FR8 — Filtrage natif conservé.** Le module n'altère PAS `getEntity()` au-delà du nécessaire : en isolation stricte, `getEntity()` continue de retourner `$conf->entity` (comportement core sans `$mc`). Aucune donnée partagée entre entités.
- **FR9 — Indicateur d'entité courante.** L'entité active est affichée en permanence dans l'UI (nom + éventuelle couleur) pour éviter toute saisie dans la mauvaise société.
- **FR10 — Compatibilité Doli2Shop.** La configuration Doli2Shop (credentials Shopify, mappings, licence) reste par entité (déjà le cas via `$conf->entity`). 1 entité = 1 société = 1 boutique = 1 licence Doli2Shop.
- **FR11 — Traductions 5 langues.** fr_FR, en_US, de_DE, es_ES, it_IT.

## 3. Exigences non fonctionnelles (NFR)

- **NFR-S1 (Isolation — CRITIQUE).** Aucun utilisateur ne peut lire/écrire des données d'une entité non autorisée, quel que soit le chemin (UI, URL forgée `?switchentity=`, API REST). Tests de sécurité obligatoires.
- **NFR-S2.** Le switch d'entité vérifie l'autorisation **côté serveur** à chaque requête (pas seulement à l'affichage du sélecteur). `GETPOST('switchentity','int')` validé contre la liste autorisée.
- **NFR-S3.** Journalisation des switches et des refus (audit).
- **NFR-C1 (Compat).** Conforme cibles Dolibarr 19→23+ ; n'utiliser que des APIs publiques (`getEntity`, `$conf->entity`, hooks). Aucune modification du core.
- **NFR-C2.** Le module reste fonctionnel s'il est désactivé : retour au comportement mono-entité `entity=1` (pas de donnée orpheline bloquante).
- **NFR-P1 (Perf).** Le calcul des entités autorisées d'un user est mis en cache par session (pas de requête répétée par page).
- **NFR-M1 (Maintenabilité).** SQL idempotent (`information_schema`, jamais `IF NOT EXISTS`), `MAIN_DB_PREFIX`, `$db->escape()`, conventions P'tite Tête (CLAUDE.dolibarr.md).
- **NFR-Sec-code.** `!defined('DOLIBARR_INC_FOR_MODULES')`, `$user->hasRight()`, `newToken()`/CSRF, `GETPOST` typé, sorties échappées.

## 4. Hypothèses & dépendances

- L'instance autorise le positionnement de `$conf->entity` via session (comportement standard `master.inc.php` — vérifié sur 23).
- Pas de module tiers exigeant Multicompany pour le multi-entité (à valider : compta, facturation, Doli2Shop).
- Isolation stricte acceptée : référentiels (produits, tiers) **non partagés** entre entités. Si un client exige le partage → hors périmètre (réévaluer Multicompany).

## 5. Critères d'acceptation globaux

1. 2 entités créées, 1 user affecté aux deux, switch fonctionnel, isolation des données vérifiée (factures/compta/tiers distincts).
2. User affecté à l'entité A uniquement : tentative `?switchentity=B` → refus + log, reste sur A.
3. Module désactivé → instance revient proprement en mono-entité.
4. Doli2Shop : 2 boutiques configurées sur 2 entités, sync indépendante, 1 licence/entité.
5. 5 langues présentes ; `php -l` OK ; tests sécurité d'isolation verts.

## 6. Indicateurs

- 0 fuite inter-entité sur la suite de tests de sécurité.
- Création + bascule d'entité < 3 clics.
- 0 dépendance externe payante.
