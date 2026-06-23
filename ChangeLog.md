# Changelog — modMultiEntity

Toutes les modifications notables de ce module sont documentées ici.
Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) · versionnage [SemVer](https://semver.org/lang/fr/).

## [0.1.0] — 2026-06-23

### Epic 1 — Fondations module & schéma

Première version : module installable, tables créées, descripteur conforme P'tite Tête.

#### Ajouté
- **Descripteur `modMultiEntity`** (`numero = 351007`, plage P'tite Tête) : famille `technic`, droits `read` / `manage`, constante `MULTIENTITY_DEBUG`, déclaration des 2 tables, conflit déclaré avec Multicompany.
- **Schéma SQL** : `llx_multientity_entity` et `llx_multientity_user_entity` (tables transverses, sans colonne `entity` — méta-gestion des entités).
- **Enregistrement automatique de l'entité 1** à l'activation, idempotent (`INSERT … ON DUPLICATE KEY UPDATE`).
- **i18n** : `multientity.lang` en 5 langues (fr_FR, en_US, de_DE, es_ES, it_IT).
- **`lib/version.lib.php`** : source unique de version + bornes de compatibilité Dolibarr.
- **`lib/multientity.lib.php`** : helpers (`multientityAdminPrepareHead`, `multientity_get_entity_label`, `multientity_get_base_url`).
- **Pages admin** : `setup.php` (configuration) et `about.php` (à propos).

#### Corrigé (suite revue de code 3-layer)
- Fallback de `multientity_get_entity_label` désormais traduit (`$langs->trans('Entity')`) + clés `Entity` / `MainEntityDefaultLabel` ajoutées aux 5 langues.
- `multientity_get_base_url()` : normalisation `rtrim(DOL_URL_ROOT, '/')` (évite un double `DOL_URL_ROOT` si slash final).
- `multientity_get_entity_label` : libération de la ressource SQL (`$db->free()`).
- `init()` propage l'erreur de `registerDefaultEntity()` ; INSERT entité 1 encadré par une transaction ; label par défaut internationalisé.
- Squelette de migration `sql/update_multientity_0.1.0_0.2.0.sql` (pattern `information_schema`).

#### Notes
- Aucun patch du core : réutilisation du socle multi-entité natif (colonne `entity`, `getEntity()`, `master.inc.php`).
- Les hooks (sélecteur d'entité, interception du switch) seront livrés à l'Epic 3 ; aucun hook actif en Epic 1 pour garantir une activation/désactivation propre.
- Isolation stricte : aucun référentiel partagé entre entités.
- Reste avant clôture Epic 1 : test d'activation/désactivation runtime sur Dolibarr 23 (et idéalement 19/21).
