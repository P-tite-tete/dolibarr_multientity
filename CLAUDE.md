# Guide Claude — modMultiEntity (Multi-Entité)

> Conventions Dolibarr partagées (suite P'tite Tête) :
> @../CLAUDE.dolibarr.md

## Le module en deux lignes

Gestion multi-entité (multi-société) maison pour Dolibarr, en **isolation stricte**, alternative gratuite à Multicompany. Module `numero = 351007` (plage P'tite Tête 351000-351099). Cible Dolibarr 19→23+.

## Principe d'architecture (NE PAS DÉVIER)

- **Le multi-entité est natif dans le core** (colonne `entity` partout — compta incluse —, `getEntity()`, `master.inc.php` lit `$_SESSION['dol_entity']`). Le module ajoute **uniquement l'orchestration** : table d'entités, affectation users, switch sécurisé.
- **Zéro patch du core.** N'utiliser que les APIs publiques (`getEntity`, `$conf->entity`, hooks, `$_SESSION['dol_entity']`).
- **Isolation STRICTE** : aucun partage de référentiels entre entités (≠ Multicompany). Ne pas surcharger `getEntity()` (laisser le comportement strict natif = `$conf->entity`).

## Invariant critique — SÉCURITÉ D'ISOLATION

Le risque #1 est la **fuite de données inter-société**. Toute valeur d'entité posée (`switchentity`, session, API) doit être **validée côté serveur** contre les entités autorisées de l'utilisateur. Jamais livrer le switch d'entité (Epic 3) sans les tests d'isolation (story 3-5) au vert. Journaliser les refus.

## État

Cadrage BMAD complet dans `docs/planning-artifacts/` + `docs/implementation-artifacts/sprint-status.yaml`. Pas encore de code module. Ordre de dev : E1 → E2 → E3 → E4 (E5 transverse).

## Lien suite P'tite Tête

- **Doli2Shop** : déjà multi-entité (filtre `entity`, config par entité). Ce module débloque le cas « N boutiques = sociétés distinctes » → 1 entité = 1 société = 1 boutique = 1 licence. Le cas « même société, N boutiques » relève du multi-store interne Doli2Shop (autre chantier).
