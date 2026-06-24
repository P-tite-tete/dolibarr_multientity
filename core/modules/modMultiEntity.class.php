<?php
/**
 * @file        core/modules/modMultiEntity.class.php
 * @brief       Descripteur principal du module MultiEntity
 *
 * Gestion multi-entité (multi-société) maison en isolation stricte,
 * alternative gratuite à Multicompany. Le multi-entité est natif dans le
 * core Dolibarr (colonne `entity` partout, getEntity(), master.inc.php) ;
 * ce module n'ajoute QUE l'orchestration (table d'entités, affectation
 * users, switch sécurisé). Zéro patch du core.
 *
 * @package     MultiEntity
 * @subpackage  Core
 * @category    core
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 * @link        http://www.dolibarr.org
 * @link        https://www.ptitetete.org
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

// Source unique de la version
require_once dirname(__DIR__, 2) . '/lib/version.lib.php';

/**
 * Classe descripteur du module MultiEntity
 */
class modMultiEntity extends DolibarrModules
{
	/**
	 * Constructeur du module
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Identifiants du module (plage P'tite Tête 351000-351099 — ne pas modifier)
		$this->numero = 351007;
		$this->rights_class = 'multientity';

		// Informations générales du module
		$this->family = "technic";
		$this->module_position = '1000';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Gestion multi-entité (multi-société) en isolation stricte";
		$this->descriptionlong = "Gestion multi-entité maison pour Dolibarr en isolation stricte : "
			. "création d'entités, affectation des utilisateurs et bascule sécurisée entre sociétés "
			. "sur une instance unique. Alternative gratuite à Multicompany, sans aucun patch du core "
			. "(réutilise le socle multi-entité natif : colonne entity, getEntity(), master.inc.php).";

		// Version du module (source unique : lib/version.lib.php)
		$this->version = MULTIENTITY_MODULE_VERSION;
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

		// Informations éditeur
		$this->editor_name = "P'tite Tête";
		$this->editor_url = "https://www.ptitetete.org";

		// Icône du module (picto générique core, image dédiée à venir)
		$this->picto = 'building';

		// Dépendances
		$this->depends = array();      // Aucun module requis (socle natif du core)
		$this->requiredby = array();
		$this->conflictwith = array('modMulticompany'); // Multicompany gère déjà le multi-entité

		// Compatibilité versions
		$this->phpmin = array(7, 4, 0);
		$this->need_dolibarr_version = array(19, 0, 0);

		// Fichiers de langue
		$this->langfiles = array("multientity@multientity");

		// Pages de configuration
		$this->config_page_url = array("setup.php@multientity");

		// Constantes du module
		$this->const = array(
			0 => array('MULTIENTITY_DEBUG', 'chaine', '0', 'Mode debug du module MultiEntity (0=non, 1=oui)', 0, 'current'),
		);

		// Permissions
		$this->rights = array();
		$r = 0;

		// Lire / accéder au module
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 35100701
		$this->rights[$r][1] = 'PermissionRead';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;

		// Gérer les entités et les affectations utilisateurs (super-admin)
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 35100702
		$this->rights[$r][1] = 'PermissionManage';
		$this->rights[$r][4] = 'manage';
		$this->rights[$r][5] = '';
		$r++;

		// Menus (ajoutés dans les epics ultérieurs : CRUD entités, affectation users)
		$this->menu = array();

		// Hooks & triggers
		// Triggers activés depuis story 3.1 : le trigger USER_LOGIN résout l'entité
		// d'ouverture de session (interface_99_modMultiEntity_LoginEntity).
		// Hook toprightmenu activé en story 3.2 : ActionsMultientity::printTopRightMenu
		// injecte l'indicateur d'entité courante + sélecteur dans la barre supérieure.
		// ⚠️ Le switch lui-même n'est pas sécurisé avant 3.3/3.4/3.5.
		$this->module_parts = array(
			'triggers' => 1,
			'hooks'    => array('toprightmenu'),
		);

		// Tables créées à l'activation (via _load_tables sur sql/)
		$this->tables = array(
			"multientity_entity",
			"multientity_user_entity",
		);
	}

	/**
	 * Fonction appelée lors de l'activation du module.
	 *
	 * Crée les tables puis enregistre automatiquement l'entité 1 (existante)
	 * de façon idempotente (FR1).
	 *
	 * @param string $options Options
	 * @return int 1 si OK, <0 si erreur
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Création des tables du module
		$result = $this->_load_tables('/multientity/sql/');
		if ($result < 0) {
			return -1; // Erreur (déjà journalisée par _load_tables)
		}

		// Enregistrement idempotent de l'entité 1 (instance mono-entité existante)
		if ($this->registerDefaultEntity() < 0) {
			return -1;
		}

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Fonction appelée lors de la désactivation du module.
	 *
	 * Les tables et données sont conservées (désactivation réversible, NFR-C2).
	 * À la désactivation, $_SESSION['dol_entity'] n'est plus posée par le module :
	 * le core retombe sur entity=1 (mono-entité propre).
	 *
	 * @param string $options Options
	 * @return int 1 si OK, <0 si erreur
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}

	/**
	 * Enregistre l'entité 1 dans llx_multientity_entity si absente (idempotent).
	 *
	 * @return int 1 si OK, <0 si erreur
	 */
	private function registerDefaultEntity()
	{
		global $langs;

		$langs->load("multientity@multientity");
		$label = $langs->trans("MainEntityDefaultLabel");
		// Fallback si la traduction n'est pas résolue
		if ($label === "MainEntityDefaultLabel") {
			$label = "Entité principale";
		}

		$now = $this->db->idate(dol_now());

		$this->db->begin();

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "multientity_entity";
		$sql .= " (entity_id, label, code, active, date_creation)";
		$sql .= " VALUES (1, '" . $this->db->escape($label) . "', '" . $this->db->escape('MAIN') . "', 1, '" . $this->db->escape($now) . "')";
		$sql .= " ON DUPLICATE KEY UPDATE tms = tms"; // no-op si l'entité 1 existe déjà

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			dol_syslog(__METHOD__ . " Erreur enregistrement entité 1 : " . $this->db->lasterror(), LOG_ERR);
			return -1;
		}

		$this->db->commit();
		dol_syslog(__METHOD__ . " Entité 1 enregistrée (idempotent)", LOG_INFO);
		return 1;
	}
}
