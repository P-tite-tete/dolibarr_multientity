-- Date: 2026-06-23
-- Version: 0.1.0
-- Description: Table des entités gérées (référence les valeurs de $conf->entity).
--              Table TRANSVERSE volontairement GLOBALE : pas de colonne `entity`
--              (entity_id y est une donnée métier, pas un filtre de tenant).
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <support@ptitetete.org>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Creation script for MultiEntity module version 0.1.0

CREATE TABLE llx_multientity_entity (
	rowid           integer AUTO_INCREMENT PRIMARY KEY,
	entity_id       integer NOT NULL,             -- valeur de $conf->entity représentée (1, 2, 3…)
	label           varchar(128) NOT NULL,
	code            varchar(32) DEFAULT NULL,     -- code court (ex. SCI, SARL1)
	color           varchar(7) DEFAULT NULL,      -- pastille UI (#RRGGBB)
	active          tinyint NOT NULL DEFAULT 1,
	date_creation   datetime DEFAULT NULL,
	tms             timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_multientity_entity (entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
