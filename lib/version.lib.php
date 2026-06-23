<?php
/**
 * @file        lib/version.lib.php
 * @brief       Source unique de la version du module MultiEntity
 *
 * Ce fichier est la SEULE source de vérité pour le numéro de version.
 * Tous les autres fichiers (descripteur, lib, packaging) doivent lire la
 * constante MULTIENTITY_MODULE_VERSION définie ici.
 *
 * @package     MultiEntity
 * @subpackage  Lib
 * @category    lib
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 */

// =============================================
// VERSION MODULE — SOURCE UNIQUE
// =============================================

if (!defined('MULTIENTITY_MODULE_VERSION')) {
	/**
	 * Version actuelle du module MultiEntity
	 * Format : MAJOR.MINOR.PATCH (semver)
	 */
	define('MULTIENTITY_MODULE_VERSION', '0.1.0');
}

if (!defined('MULTIENTITY_MIN_DOLIBARR_VERSION')) {
	/**
	 * Version minimale de Dolibarr supportée
	 */
	define('MULTIENTITY_MIN_DOLIBARR_VERSION', '19.0.0');
}

if (!defined('MULTIENTITY_MAX_DOLIBARR_VERSION')) {
	/**
	 * Version maximale de Dolibarr supportée
	 */
	define('MULTIENTITY_MAX_DOLIBARR_VERSION', '23.99.99');
}

/**
 * Retourne la version du module
 *
 * @return string Version au format semver (ex: "0.1.0")
 */
function multientity_get_version()
{
	return MULTIENTITY_MODULE_VERSION;
}
