# modMultiEntity — Multi-Entité pour Dolibarr

Module Dolibarr (P'tite Tête, `numero = 351007`) de gestion **multi-entité / multi-société** sur une instance unique, en **isolation stricte**, sans dépendance au module commercial Multicompany.

> Statut : **cadrage / planification** (BMAD). Aucun code module à ce stade — voir `docs/planning-artifacts/`.

## Pourquoi

Gérer plusieurs sociétés juridiquement distinctes (ex. plusieurs boutiques Shopify = plusieurs sociétés) dans une seule install Dolibarr. Le multi-entité est **natif dans le core** (colonne `entity` partout, compta incluse ; `getEntity()` ; `master.inc.php` lit `$_SESSION['dol_entity']`) — il manque seulement la **couche d'orchestration** (créer des entités, basculer, affecter les utilisateurs), que ce module fournit. Alternative à Multicompany (330 € HT, pas de version libre compatible Dolibarr 23).

## Périmètre

- ✅ CRUD entités, affectation user↔entités, switch sécurisé, init d'entité, i18n 5 langues.
- ❌ Hors périmètre : partage de référentiels entre entités, numérotation cross-entité, consolidation (la complexité de Multicompany — non requise pour « sociétés distinctes »).

## Deux voies multi-boutique (à ne pas confondre)

| Besoin | Solution |
|---|---|
| N boutiques, **même société** | multi-store interne Doli2Shop (`entity=1`) — *autre chantier, côté Doli2Shop* |
| N boutiques, **sociétés distinctes** | **ce module** (multi-entité) + Doli2Shop configuré par entité |

## Sécurité

Le risque central est la **fuite inter-société**. Tout repose sur le contrôle d'accès au switch d'entité (validation serveur, défense en profondeur, audit). Voir NFR-S1/S2/S3 du PRD. Ne jamais livrer le switch sans ses tests d'isolation.

## Documentation de cadrage

- `docs/planning-artifacts/product-brief.md`
- `docs/planning-artifacts/prd.md`
- `docs/planning-artifacts/architecture.md`
- `docs/planning-artifacts/epics.md`
- `docs/implementation-artifacts/sprint-status.yaml`

## Licence

GPL v3+. © 2024-2026 P'tite Tête.
