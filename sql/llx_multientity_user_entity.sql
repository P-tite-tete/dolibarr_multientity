-- Date: 2026-06-23
-- Version: 0.1.0
-- Description: Affectation des utilisateurs aux entités autorisées + entité par défaut.
--              Table TRANSVERSE volontairement GLOBALE : pas de colonne `entity`
--              (méta-gestion des entités, entity_id est une donnée).
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <support@ptitetete.org>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Creation script for MultiEntity module version 0.1.0

CREATE TABLE llx_multientity_user_entity (
	rowid           integer AUTO_INCREMENT PRIMARY KEY,
	fk_user         integer NOT NULL,
	entity_id       integer NOT NULL,
	is_default      tinyint NOT NULL DEFAULT 0,
	tms             timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_multientity_user_entity (fk_user, entity_id),
	KEY idx_multientity_user (fk_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
