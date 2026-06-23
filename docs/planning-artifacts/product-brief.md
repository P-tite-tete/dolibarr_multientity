# Product Brief — modMultiEntity (Multi-Entité)

Date : 2026-06-23
Auteur : P'tite Tête
Statut : Draft (cadrage initial)

## 1. Problème

Plusieurs clients de l'Association P'tite Tête (notamment utilisateurs de Doli2Shop) exploitent **plusieurs boutiques Shopify rattachées à des sociétés juridiquement distinctes** et veulent les gérer dans **une seule installation Dolibarr**. Or :

- Mettre plusieurs sociétés dans une seule entité Dolibarr (`entity = 1`) est **comptablement et légalement interdit** : mélange des factures, séquences de numérotation, TVA, exercices et grand livre.
- Le seul module qui apporte la gestion multi-entité (multi-société) est **Multicompany** de Régis Houssin (iNodbox). Sa version maintenue compatible Dolibarr 21/22/23 est **payante (330 € HT)** sur DoliStore ; la version GPL gratuite sur GitHub plafonne à **Dolibarr 20** (fév 2025) — inutilisable sur la cible 23.

**Constat technique clé (vérifié sur Dolibarr 23 + BDD prod MariaDB 11.8)** : l'isolation multi-société est **native dans le core** — la colonne `entity` est présente sur toutes les tables-objets, **compta incluse** (`llx_accounting_bookkeeping`, journaux, comptes, factures, tiers, banque, paiements). `getEntity()` filtre sur `$conf->entity`, et `master.inc.php` positionne déjà `$conf->entity` depuis la session (`$_SESSION['dol_entity']`) / `GETPOST('switchentity')`. **Ce qui manque sans Multicompany, ce n'est PAS le stockage ni la compta : c'est uniquement la couche d'orchestration** (créer des entités, basculer entre elles, affecter les utilisateurs).

## 2. Opportunité

Développer un module maison **léger** qui fournit cette orchestration en **isolation stricte** (sans partage de référentiels), en s'appuyant à ~80 % sur le multi-entité déjà natif du core. Bénéfices :

- Débloque le scénario « N sociétés / N boutiques sur une instance » sans coût de licence tiers récurrent.
- Cohérent avec la suite P'tite Tête (Doli2Shop fonctionne déjà par `entity`).
- Maîtrise totale (pas de dépendance commerciale à iNodbox), code GPL.

## 3. Solution proposée (vision)

`modMultiEntity` : module Dolibarr (plage P'tite Tête, `numero = 351007`) qui ajoute :
1. la gestion d'entités (créer / activer / désactiver / lister), via une table `llx_multientity_entity` (le core n'a pas de table d'entités) ;
2. l'affectation **utilisateur ↔ entités autorisées** (+ entité par défaut) ;
3. le **sélecteur / switch d'entité** (pose `$_SESSION['dol_entity']`, mécanisme déjà lu par le core) avec **contrôle d'accès strict** ;
4. l'initialisation d'une nouvelle entité (constantes de base, société émettrice).

**Isolation stricte** : aucune donnée partagée entre entités (pas de référentiels communs produits/tiers). C'est le choix qui rend le module simple et sûr, et qui suffit au cas « sociétés distinctes ».

## 4. Périmètre (in / out)

**In (MVP)**
- CRUD entités + activation.
- Affectation users ↔ entités, entité par défaut au login.
- Switch d'entité sécurisé (UI + contrôle d'accès).
- Initialisation entité (constantes société, coordonnées, devise).
- Compatibilité Doli2Shop (config Shopify par entité — déjà le cas).

**Out (explicitement exclu du MVP)**
- Partage de référentiels entre entités (produits/tiers/contacts communs) → la grande complexité de Multicompany ; non requis pour « sociétés distinctes ».
- Numérotation/séquences cross-entité, consolidation comptable inter-société.
- Transferts d'objets entre entités.
- Gestion fine des droits cross-entité au-delà de « autorisé / non autorisé ».

## 5. Utilisateurs cibles

- **Admin multi-sociétés** : gère 2-N sociétés (ex. holding + filiales, ou entrepreneur avec plusieurs structures), une boutique Shopify par société.
- **Client Doli2Shop multi-boutiques** dont les boutiques sont des sociétés distinctes (ex. prospect type Échafaudages Stéphanois / Julien Bertrande à qualifier).

## 6. Alternatives considérées

| Option | Coût | Verdict |
|---|---|---|
| **Multicompany DoliStore** | 330 € HT + renouvellement | Solution mûre/maintenue ; rejetée par choix (dépendance + coût récurrent) |
| Multicompany GitHub gratuit | 0 € | Plafonne à Dolibarr 20 → **inutilisable sur 23** |
| **modMultiEntity maison** (ce brief) | dev interne | Retenu : socle natif, isolation stricte, périmètre maîtrisé |
| Multi-store Doli2Shop (entity=1) | dev interne | Complémentaire — couvre le cas « **même** société, N boutiques », PAS « sociétés distinctes » |

## 7. Risques principaux

- **Sécurité d'isolation (risque #1)** : un bug dans le contrôle d'accès au switch = un utilisateur accède aux données (compta) d'une autre société → fuite grave. À traiter comme exigence centrale (tests dédiés).
- **Maintenance compat Dolibarr** : suivre les évolutions de `master.inc.php`/`getEntity()` entre versions 19→23+.
- **Effet de bord modules tiers** : certains modules supposent Multicompany pour le multi-entité ; valider Doli2Shop, compta, facturation en isolation stricte.
- **Confusion produit** : bien distinguer des deux voies (multi-store même société vs multi-entité sociétés distinctes).

## 8. Critères de succès

- Créer 2 entités, y affecter un utilisateur, basculer entre elles, et constater une **isolation totale** des données (factures, compta, tiers) — vérifiée par tests.
- Un utilisateur non autorisé sur une entité **ne peut en aucun cas** y accéder (test de sécurité).
- Doli2Shop configurable indépendamment par entité (1 boutique + 1 licence par entité).
- Zéro dépendance à Multicompany.
