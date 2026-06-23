<?php
/**
 * @file        lib/multientity.lib.php
 * @brief       Fonctions communes pour le module MultiEntity
 *
 * @package     MultiEntity
 * @subpackage  Lib
 * @category    lib
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 * @link        http://www.dolibarr.org
 * @link        https://www.ptitetete.org
 */

/**
 * Obtenir le chemin de base du module MultiEntity (relatif à DOL_URL_ROOT).
 *
 * @return string Chemin de base du module (ex. "/custom/multientity")
 */
function multientity_get_base_url()
{
	$url = dol_buildpath('/multientity', 1);
	$dol_url_root = rtrim(DOL_URL_ROOT, '/');
	if (!empty($dol_url_root) && strpos($url, $dol_url_root) === 0) {
		return substr($url, strlen($dol_url_root));
	}

	return $url;
}

/**
 * Préparer les onglets de la zone d'administration du module.
 *
 * @return array Tableau des onglets (format Dolibarr head)
 */
function multientityAdminPrepareHead()
{
	global $langs, $conf;

	$langs->loadLangs(array("admin", "multientity@multientity"));

	$base_url = multientity_get_base_url();

	$h = 0;
	$head = array();

	$head[$h][0] = DOL_URL_ROOT . $base_url . '/admin/setup.php';
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = DOL_URL_ROOT . $base_url . '/admin/entities.php';
	$head[$h][1] = $langs->trans("Entities");
	$head[$h][2] = 'entities';
	$h++;

	$head[$h][0] = DOL_URL_ROOT . $base_url . '/admin/user_entities.php';
	$head[$h][1] = $langs->trans("UserEntities");
	$head[$h][2] = 'userentities';
	$h++;

	$head[$h][0] = DOL_URL_ROOT . $base_url . '/admin/about.php';
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'multientity@multientity');

	return $head;
}

/**
 * Retourne le libellé de l'entité courante ($conf->entity) tel qu'enregistré
 * dans llx_multientity_entity. Fallback sur le numéro brut si absent.
 *
 * @param DoliDB   $db          Database handler
 * @param int|null $entity_id   Entité à résoudre (défaut : $conf->entity)
 * @return string               Libellé de l'entité
 */
function multientity_get_entity_label($db, $entity_id = null)
{
	global $conf, $langs;

	if ($entity_id === null) {
		$entity_id = (int) $conf->entity;
	}
	$entity_id = (int) $entity_id;

	$sql = "SELECT label FROM " . MAIN_DB_PREFIX . "multientity_entity";
	$sql .= " WHERE entity_id = " . $entity_id;

	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		return $obj->label;
	}
	if ($resql) {
		$db->free($resql);
	}

	return $langs->trans('Entity') . ' ' . $entity_id;
}
