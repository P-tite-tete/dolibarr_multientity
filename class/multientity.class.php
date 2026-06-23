<?php
/**
 * @file        class/multientity.class.php
 * @brief       Classe service Multientity — point unique d'accès aux tables
 *              llx_multientity_entity et llx_multientity_user_entity.
 *
 * Ce service encapsule toute la logique SQL relative aux entités et à
 * l'affectation des utilisateurs. Les pages admin, l'init du module et
 * les futurs contrôleurs d'accès (E3/E6) DOIVENT passer par cette classe
 * — aucun SQL dispersé hors de ce fichier.
 *
 * Pas de méthode delete() : la suppression d'entité n'est pas supportée
 * (désactivation seulement, cf. AC#6 et prd FR2). L'entité 1 ne peut
 * jamais être désactivée (garde AC#5). Le renommage/recoloriage de
 * l'entité 1 est PERMIS via updateEntity() (seule sa désactivation est
 * interdite).
 *
 * @package     MultiEntity
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 * @link        http://www.dolibarr.org
 * @link        https://www.ptitetete.org
 */

/**
 * Service d'accès aux tables d'orchestration multi-entité.
 *
 * Tables gérées (transverses, sans colonne `entity`) :
 *   - llx_multientity_entity       : référentiel des entités
 *   - llx_multientity_user_entity  : affectation utilisateurs ↔ entités
 */
class Multientity
{
	/** @var DoliDB $db Handler base de données */
	public $db;

	/** @var string $error Dernier message d'erreur */
	public $error = '';

	/** @var string[] $errors Liste des messages d'erreur */
	public $errors = array();

	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Handler base de données Dolibarr
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// -------------------------------------------------------------------------
	// Lecture
	// -------------------------------------------------------------------------

	/**
	 * Retourne la liste de toutes les entités, triées par entity_id.
	 *
	 * @param bool $activeOnly Si true, ne retourne que les entités actives (active=1).
	 * @return object[]|int Tableau d'objets (entity_id, label, code, color, active)
	 *                      ou <0 en cas d'erreur SQL.
	 */
	public function listEntities($activeOnly = false)
	{
		$sql = "SELECT entity_id, label, code, color, active";
		$sql .= " FROM " . MAIN_DB_PREFIX . "multientity_entity";
		if ($activeOnly) {
			$sql .= " WHERE active = 1";
		}
		$sql .= " ORDER BY entity_id";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur SQL : " . $this->error, LOG_ERR);
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$list[] = $obj;
		}
		$this->db->free($resql);

		return $list;
	}

	/**
	 * Retourne une entité unique par son entity_id.
	 *
	 * @param int $entity_id Identifiant de l'entité.
	 * @return object|null Objet entité (entity_id, label, code, color, active)
	 *                     ou null si absente, ou <0 en cas d'erreur SQL.
	 */
	public function getEntity($entity_id)
	{
		$entity_id = (int) $entity_id;

		$sql = "SELECT entity_id, label, code, color, active";
		$sql .= " FROM " . MAIN_DB_PREFIX . "multientity_entity";
		$sql .= " WHERE entity_id = " . $entity_id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur SQL entity_id=" . $entity_id . " : " . $this->error, LOG_ERR);
			return -1;
		}

		$obj = null;
		if ($this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
		}
		$this->db->free($resql);

		return $obj;
	}

	/**
	 * Retourne le libellé d'une entité.
	 *
	 * Délègue à multientity_get_entity_label() (source unique, AC#7).
	 *
	 * @param int|null $entity_id Identifiant de l'entité (null = entité courante).
	 * @return string Libellé de l'entité.
	 */
	public function getEntityLabel($entity_id = null)
	{
		dol_include_once('/multientity/lib/multientity.lib.php');
		return multientity_get_entity_label($this->db, $entity_id);
	}

	// -------------------------------------------------------------------------
	// Écriture
	// -------------------------------------------------------------------------

	/**
	 * Crée une nouvelle entité dans llx_multientity_entity.
	 *
	 * Le entity_id est calculé automatiquement par COALESCE(MAX(entity_id),1)+1
	 * si non fourni (safe table vide → 2). La couleur doit être au format
	 * #RRGGBB ; si invalide, elle est ignorée (null) et un warning est logué.
	 * Refuse si entity_id déjà présent (clé UNIQUE uk_multientity_entity).
	 *
	 * @param string      $label     Libellé de l'entité (obligatoire).
	 * @param string|null $code      Code court (ex. SCI, SARL1).
	 * @param string|null $color     Pastille UI au format #RRGGBB.
	 * @param int|null    $entity_id Forcer un entity_id précis (optionnel).
	 * @return int entity_id créé (>0) ou <0 en cas d'erreur.
	 */
	public function createEntity($label, $code = null, $color = null, $entity_id = null)
	{
		// Label obligatoire (refus AVANT toute transaction)
		if (!isset($label) || trim((string) $label) === '') {
			$this->error = 'Label is required and cannot be empty';
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur : label vide ou null interdit", LOG_ERR);
			return -2;
		}

		// Validation couleur
		$colorVal = null;
		if ($color !== null) {
			if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
				$colorVal = $color;
			} else {
				dol_syslog(
					__METHOD__ . " Couleur invalide ignorée : '" . $color . "' (attendu #RRGGBB)",
					LOG_WARNING
				);
			}
		}

		$this->db->begin();

		// Calcul entity_id automatique si non fourni
		if ($entity_id === null) {
			$sqlMax = "SELECT COALESCE(MAX(entity_id), 1) + 1 AS next_id";
			$sqlMax .= " FROM " . MAIN_DB_PREFIX . "multientity_entity";
			$resMax = $this->db->query($sqlMax);
			if (!$resMax) {
				$this->db->rollback();
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
				dol_syslog(__METHOD__ . " Erreur calcul entity_id : " . $this->error, LOG_ERR);
				return -1;
			}
			$objMax = $this->db->fetch_object($resMax);
			$this->db->free($resMax);
			$entity_id = (int) $objMax->next_id;
		} else {
			$entity_id = (int) $entity_id;
		}

		// entity_id doit être strictement positif (garde contre 0/négatif forcé)
		if ($entity_id <= 0) {
			$this->db->rollback();
			$this->error = 'entity_id must be > 0';
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur : entity_id=" . $entity_id . " invalide (<=0)", LOG_ERR);
			return -3;
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "multientity_entity";
		$sql .= " (entity_id, label, code, color, active, date_creation)";
		$sql .= " VALUES (";
		$sql .= $entity_id . ",";
		$sql .= " '" . $this->db->escape($label) . "',";
		$sql .= " " . ($code !== null ? "'" . $this->db->escape($code) . "'" : "NULL") . ",";
		$sql .= " " . ($colorVal !== null ? "'" . $this->db->escape($colorVal) . "'" : "NULL") . ",";
		$sql .= " 1,";
		$sql .= " '" . $this->db->idate(dol_now()) . "'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(
				__METHOD__ . " Erreur INSERT entity_id=" . $entity_id . " : " . $this->error,
				LOG_ERR
			);
			return -1;
		}

		$this->db->commit();
		dol_syslog(__METHOD__ . " Entité créée entity_id=" . $entity_id . " label=" . dol_escape_htmltag($label), LOG_INFO);

		return $entity_id;
	}

	/**
	 * Active ou désactive une entité.
	 *
	 * Garde : l'entité 1 (principale) ne peut jamais être désactivée.
	 * Ce guard est évalué AVANT toute ouverture de transaction.
	 *
	 * @param int  $entity_id Identifiant de l'entité.
	 * @param bool $active    true pour activer, false pour désactiver.
	 * @return int 1 si OK, <0 en cas d'erreur ou de refus.
	 */
	public function setActive($entity_id, $active)
	{
		$entity_id = (int) $entity_id;

		// Guard AVANT begin() — pas de transaction zombie
		if ($entity_id === 1 && !$active) {
			dol_syslog(
				__METHOD__ . " Refus : désactivation de l'entité 1 interdite (entité principale)",
				LOG_WARNING
			);
			$this->error = 'Cannot deactivate entity 1 (main entity)';
			$this->errors[] = $this->error;
			return -1;
		}

		$activeInt = $active ? 1 : 0;

		$this->db->begin();

		$sql = "UPDATE " . MAIN_DB_PREFIX . "multientity_entity";
		$sql .= " SET active = " . $activeInt;
		$sql .= " WHERE entity_id = " . $entity_id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(
				__METHOD__ . " Erreur UPDATE entity_id=" . $entity_id . " : " . $this->error,
				LOG_ERR
			);
			return -1;
		}

		$this->db->commit();
		dol_syslog(
			__METHOD__ . " entity_id=" . $entity_id . " active=" . $activeInt,
			LOG_INFO
		);

		return 1;
	}

	/**
	 * Met à jour le libellé, le code et la couleur d'une entité existante.
	 *
	 * Le entity_id est immuable et n'est jamais modifié. Il sert uniquement
	 * de critère WHERE. La couleur doit être au format #RRGGBB ; si invalide,
	 * elle est ignorée (null) et un warning est logué. Le renommage de
	 * l'entité 1 est PERMIS (seule sa désactivation est interdite).
	 *
	 * @param int         $entity_id Identifiant de l'entité (immuable, WHERE seulement).
	 * @param string      $label     Nouveau libellé (obligatoire).
	 * @param string|null $code      Code court (ex. SCI, SARL1) ; null = NULL en base.
	 * @param string|null $color     Pastille UI au format #RRGGBB ; null = NULL en base.
	 * @return int 1 si OK, <0 en cas d'erreur.
	 */
	public function updateEntity($entity_id, $label, $code = null, $color = null)
	{
		$entity_id = (int) $entity_id;

		// Label obligatoire (refus AVANT toute transaction)
		if (!isset($label) || trim((string) $label) === '') {
			$this->error = 'Label is required and cannot be empty';
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur : label vide ou null interdit", LOG_ERR);
			return -2;
		}

		// Validation couleur
		$colorVal = null;
		if ($color !== null) {
			if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
				$colorVal = $color;
			} else {
				dol_syslog(
					__METHOD__ . " Couleur invalide ignorée : '" . $color . "' (attendu #RRGGBB)",
					LOG_WARNING
				);
			}
		}

		$this->db->begin();

		$sql = "UPDATE " . MAIN_DB_PREFIX . "multientity_entity";
		$sql .= " SET label = '" . $this->db->escape($label) . "'";
		$sql .= ", code = " . ($code !== null ? "'" . $this->db->escape($code) . "'" : "NULL");
		$sql .= ", color = " . ($colorVal !== null ? "'" . $this->db->escape($colorVal) . "'" : "NULL");
		$sql .= " WHERE entity_id = " . $entity_id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(
				__METHOD__ . " Erreur UPDATE entity_id=" . $entity_id . " : " . $this->error,
				LOG_ERR
			);
			return -1;
		}

		$this->db->commit();
		dol_syslog(
			__METHOD__ . " entity_id=" . $entity_id . " label=" . dol_escape_htmltag($label),
			LOG_INFO
		);

		return 1;
	}

	// -------------------------------------------------------------------------
	// Constantes société par entité (AC#1–#4 story 2.3)
	// -------------------------------------------------------------------------

	/**
	 * Liste des clés de constantes société gérées par ce module.
	 *
	 * @var string[]
	 */
	public static $COMPANY_CONST_KEYS = array(
		'MAIN_INFO_SOCIETE_NOM',
		'MAIN_INFO_SOCIETE_ADDRESS',
		'MAIN_INFO_SOCIETE_ZIP',
		'MAIN_INFO_SOCIETE_TOWN',
		'MAIN_INFO_SOCIETE_COUNTRY',
		'MAIN_MONNAIE',
	);

	/**
	 * Pose les constantes société pour l'entité N, exclusivement via dolibarr_set_const.
	 *
	 * Seuls les paramètres fournis et non vides sont écrits (pas d'écrasement par du vide).
	 * L'écriture cible exclusivement entity = $entity_id — jamais $conf->entity ni entité 0.
	 *
	 * Clés acceptées dans $params :
	 *   MAIN_INFO_SOCIETE_NOM, MAIN_INFO_SOCIETE_ADDRESS, MAIN_INFO_SOCIETE_ZIP,
	 *   MAIN_INFO_SOCIETE_TOWN, MAIN_INFO_SOCIETE_COUNTRY (format 'rowid:CODE:Label'),
	 *   MAIN_MONNAIE (ex. 'EUR').
	 *
	 * @param int   $entity_id Identifiant de l'entité cible (doit exister).
	 * @param array $params    Tableau associatif clé => valeur (valeurs vides ignorées).
	 * @return int Nombre de constantes posées (>=0) ou -1 si l'entité n'existe pas / erreur critique.
	 */
	public function initEntityConstants($entity_id, array $params)
	{
		$entity_id = (int) $entity_id;

		// Vérification existence entité
		$existing = $this->getEntity($entity_id);
		if (!is_object($existing)) {
			$msg = __METHOD__ . " Entité introuvable entity_id=" . $entity_id . " — initEntityConstants abandonnée";
			dol_syslog($msg, LOG_ERR);
			$this->error = 'Entity ' . $entity_id . ' not found';
			$this->errors[] = $this->error;
			return -1;
		}

		// Inclusion du helper Dolibarr pour dolibarr_set_const
		if (!function_exists('dolibarr_set_const')) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		}

		$written = 0;
		foreach (self::$COMPANY_CONST_KEYS as $name) {
			if (!isset($params[$name])) {
				continue;
			}
			$value = (string) $params[$name];
			if (trim($value) === '') {
				continue; // valeur vide → on ne pose pas (AC#3)
			}

			// dolibarr_set_const($db, $name, $value, $type, $visible, $note, $entity)
			// 7e argument = entity_id cible — jamais $conf->entity (AC#4)
			$res = dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $entity_id);
			if ($res > 0) {
				$written++;
				dol_syslog(
					__METHOD__ . " entity_id=" . $entity_id . " const " . $name . " posée",
					LOG_INFO
				);
			} else {
				dol_syslog(
					__METHOD__ . " entity_id=" . $entity_id . " erreur lors de la pose de " . $name,
					LOG_WARNING
				);
			}
		}

		dol_syslog(
			__METHOD__ . " entity_id=" . $entity_id . " total=" . $written . " constantes posées",
			LOG_INFO
		);

		return $written;
	}

	/**
	 * Retourne les constantes société de l'entité N depuis llx_const.
	 *
	 * Lit exclusivement entity = $entity_id (SELECT filtré) — PAS getDolGlobalString
	 * qui lirait l'entité courante (AC#7).
	 *
	 * @param int $entity_id Identifiant de l'entité cible.
	 * @return array|int Tableau associatif name => value (peut être vide si aucune constante posée),
	 *                   ou -1 en cas d'erreur SQL.
	 */
	public function getEntityConstants($entity_id)
	{
		$entity_id = (int) $entity_id;

		// Construction liste IN sécurisée (valeurs constantes, pas de données utilisateur)
		$keysList = "'" . implode("','", array_map(array($this->db, 'escape'), self::$COMPANY_CONST_KEYS)) . "'";

		$sql = "SELECT name, value";
		$sql .= " FROM " . MAIN_DB_PREFIX . "const";
		$sql .= " WHERE entity = " . $entity_id;
		$sql .= " AND name IN (" . $keysList . ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(
				__METHOD__ . " Erreur SQL entity_id=" . $entity_id . " : " . $this->error,
				LOG_ERR
			);
			return -1;
		}

		$result = array();
		while ($obj = $this->db->fetch_object($resql)) {
			// Les constantes chaine sont stockées en clair dans llx_const (pas de chiffrement
			// sur les constantes publiques société). La valeur est directement utilisable.
			$result[$obj->name] = $obj->value;
		}
		$this->db->free($resql);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Affectation utilisateurs ↔ entités
	// -------------------------------------------------------------------------

	/**
	 * Remplace l'ensemble des affectations entités d'un utilisateur.
	 *
	 * Opération atomique : DELETE de toutes les lignes de fk_user suivi
	 * des INSERT des nouvelles affectations, le tout dans une transaction.
	 * Rollback complet si une insertion échoue.
	 *
	 * Validations AVANT toute écriture :
	 *  - fk_user doit exister dans llx_user avec statut=1 (actif)
	 *  - chaque entity_id doit exister et être actif (active=1)
	 *  - si entity_ids non vide, default_entity_id doit être ∈ entity_ids
	 *  - si entity_ids vide, default_entity_id est ignoré (suppression totale OK)
	 *
	 * Codes retour :
	 *   1  = OK
	 *  -1  = Erreur SQL (transaction rollbackée)
	 *  -2  = fk_user inexistant ou inactif
	 *  -3  = entity_id invalide ou inactif
	 *  -4  = default_entity_id hors entity_ids autorisés
	 *
	 * @param int      $fk_user           Identifiant de l'utilisateur (doit être actif).
	 * @param int[]    $entity_ids        Liste des entity_id autorisés.
	 * @param int|null $default_entity_id Entity par défaut (obligatoire si entity_ids non vide).
	 * @return int 1 si OK, <0 en cas d'erreur ou de refus.
	 */
	public function setUserEntities($fk_user, array $entity_ids, $default_entity_id = null)
	{
		$fk_user = (int) $fk_user;

		// Normalisation : cast en int, déduplique, filtre <=0
		$entity_ids = array_unique(
			array_filter(
				array_map('intval', $entity_ids),
				function ($v) { return $v > 0; }
			)
		);

		// --- Validation fk_user : doit exister et être actif ---
		$sqlUser = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user";
		$sqlUser .= " WHERE rowid = " . $fk_user . " AND statut = 1";

		$resUser = $this->db->query($sqlUser);
		if (!$resUser) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur SQL vérif user fk_user=" . $fk_user . " : " . $this->error, LOG_ERR);
			return -1;
		}
		$userExists = ($this->db->num_rows($resUser) > 0);
		$this->db->free($resUser);

		if (!$userExists) {
			$this->error = 'User ' . $fk_user . ' not found or inactive';
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Refus : fk_user=" . $fk_user . " inexistant ou inactif", LOG_WARNING);
			return -2;
		}

		// --- Validation entités (si liste non vide) ---
		if (!empty($entity_ids)) {
			foreach ($entity_ids as $eid) {
				$ent = $this->getEntity($eid);
				// getEntity retourne null si absent, -1 si erreur SQL, objet si trouvé
				if ($ent === -1) {
					// Erreur SQL déjà consignée par getEntity
					return -1;
				}
				if (!is_object($ent) || (int) $ent->active !== 1) {
					$this->error = 'Entity ' . $eid . ' not found or inactive';
					$this->errors[] = $this->error;
					dol_syslog(__METHOD__ . " Refus : entity_id=" . $eid . " inexistant ou inactif, fk_user=" . $fk_user, LOG_WARNING);
					return -3;
				}
			}

			// --- Validation default_entity_id ∈ entity_ids ---
			$default_entity_id = (int) $default_entity_id;
			if (!in_array($default_entity_id, $entity_ids, true)) {
				$this->error = 'default_entity_id ' . $default_entity_id . ' not in authorized entity_ids';
				$this->errors[] = $this->error;
				dol_syslog(__METHOD__ . " Refus : default_entity_id=" . $default_entity_id . " hors autorisés, fk_user=" . $fk_user, LOG_WARNING);
				return -4;
			}
		} else {
			// Suppression totale : default ignoré
			$default_entity_id = 0;
		}

		// --- Transaction atomique DELETE + INSERT ---
		$this->db->begin();

		// DELETE toutes les affectations existantes du user
		$sqlDel = "DELETE FROM " . MAIN_DB_PREFIX . "multientity_user_entity";
		$sqlDel .= " WHERE fk_user = " . $fk_user;

		$resDel = $this->db->query($sqlDel);
		if (!$resDel) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur DELETE fk_user=" . $fk_user . " : " . $this->error, LOG_ERR);
			return -1;
		}

		// INSERT nouvelles affectations
		foreach ($entity_ids as $eid) {
			$isDefault = ($eid === $default_entity_id) ? 1 : 0;

			$sqlIns = "INSERT INTO " . MAIN_DB_PREFIX . "multientity_user_entity";
			$sqlIns .= " (fk_user, entity_id, is_default)";
			$sqlIns .= " VALUES (" . $fk_user . ", " . (int) $eid . ", " . $isDefault . ")";

			$resIns = $this->db->query($sqlIns);
			if (!$resIns) {
				$this->db->rollback();
				$this->error = $this->db->lasterror();
				$this->errors[] = $this->error;
				dol_syslog(
					__METHOD__ . " Erreur INSERT fk_user=" . $fk_user . " entity_id=" . $eid . " : " . $this->error,
					LOG_ERR
				);
				return -1;
			}
		}

		$this->db->commit();
		dol_syslog(
			__METHOD__ . " fk_user=" . $fk_user . " entités=" . implode(',', $entity_ids) . " défaut=" . $default_entity_id,
			LOG_INFO
		);

		return 1;
	}

	// -------------------------------------------------------------------------
	// Résolution utilisateur
	// -------------------------------------------------------------------------

	/**
	 * Retourne les entités autorisées d'un utilisateur.
	 *
	 * Jointure sur llx_user avec filtre statut=1 : un utilisateur
	 * supprimé/inactif ne doit jamais hériter d'entités (gestion orphelins, A2
	 * retro E1). Pas de filtre sur u.entity (tables transverses).
	 *
	 * @param int $fk_user Identifiant de l'utilisateur.
	 * @return object[]|int Tableau d'objets (entity_id, is_default),
	 *                      liste vide si user inactif/supprimé,
	 *                      ou <0 en cas d'erreur SQL.
	 */
	public function getUserEntities($fk_user)
	{
		$fk_user = (int) $fk_user;

		$sql = "SELECT ue.entity_id, ue.is_default";
		$sql .= " FROM " . MAIN_DB_PREFIX . "multientity_user_entity ue";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = ue.fk_user AND u.statut = 1";
		$sql .= " WHERE ue.fk_user = " . $fk_user;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(
				__METHOD__ . " Erreur SQL fk_user=" . $fk_user . " : " . $this->error,
				LOG_ERR
			);
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$list[] = $obj;
		}
		$this->db->free($resql);

		return $list;
	}

	/**
	 * Retourne la liste autoritaire des entités accessibles à un utilisateur.
	 *
	 * Source unique de vérité pour l'isolation (réutilisée par stories 3.3 et 3.4).
	 * Le résultat est TOUJOURS non vide : au minimum {1} (fallback sécurisé).
	 *
	 * Règles :
	 *  - Croisement getUserEntities($fk_user) ∩ listEntities(true) (entités actives).
	 *    Corrige F-12 (getUserEntities ne filtre pas l'état actif des entités).
	 *  - Si le user a 0 affectation → retourne {1} + LOG_INFO (fallback mono-entité,
	 *    rétrocompat install non configurée).
	 *  - Si le user a des affectations mais TOUTES inactives (intersection vide) →
	 *    retourne {1} + LOG_WARNING (situation anormale, sécurité). ⚠️ COMPROMIS C1-1 :
	 *    ce fallback {1} donne accès à l'entité 1 à un user qui n'y est pas affecté.
	 *    Acceptable au login (fail-safe, non exploitable), mais la story 3.3 (garde
	 *    d'accès aux données) DOIT refuser l'accès aux données de l'entité 1 si le user
	 *    n'y est pas explicitement affecté (cf. action item retro E2 / AC à créer en 3.3).
	 *  - Sinon → retourne l'intersection triée par entity_id croissant.
	 *
	 * ⚠️ NOTE CACHE SESSION : $_SESSION['multientity_allowed_entities'] est un cache
	 * de PERFORMANCE uniquement. Cette méthode (requête serveur) est la SEULE autorité.
	 * Ne JAMAIS autoriser un switch ou un accès sur la seule base du cache session
	 * (risque de session forgée). Stories 3.3/3.4 DOIVENT appeler cette méthode.
	 *
	 * Pas de filtre $conf->entity : tables transverses (llx_multientity_*).
	 * Pas de SQL direct nouveau : compose getUserEntities() + listEntities(true).
	 *
	 * Contrat de retour (FAIL-CLOSED sur erreur) :
	 *   - `{1}` UNIQUEMENT pour le cas légitime « 0 affectation » (rétrocompat mono-entité).
	 *   - tableau d'entity_id (trié croissant) pour un user avec affectations actives.
	 *   - **tableau VIDE `array()`** en cas d'erreur SQL OU si toutes les entités du user
	 *     sont inactives → le trigger NE POSE AUCUNE entité (pas d'octroi de l'entité 1).
	 *
	 * @param int $fk_user Identifiant de l'utilisateur (sera casté en int).
	 * @return int[] Tableau d'entity_id (int) ; vide = aucun accès légitime (fail-closed).
	 */
	public function getAllowedEntities($fk_user)
	{
		$fk_user = (int) $fk_user;

		// --- Affectations du user (filtre user actif natif de getUserEntities) ---
		$userRows = $this->getUserEntities($fk_user);

		if ($userRows === -1) {
			// Erreur SQL (déjà loguée) — FAIL-CLOSED : scope vide, pas d'octroi d'entité.
			// Le trigger refusera de poser une entité ; ne JAMAIS retomber sur {1} ici
			// (un échec technique ne doit pas être indistinguable d'un accès légitime).
			dol_syslog(
				__METHOD__ . " Erreur SQL getUserEntities fk_user=" . $fk_user
					. " — FAIL-CLOSED (scope vide)",
				LOG_ERR
			);
			return array();
		}

		if (empty($userRows)) {
			// 0 affectation : fallback mono-entité {1} — DÉCISION PRODUIT EXPLICITE
			// (rétrocompat : un user jamais configuré reste sur l'entité principale,
			// comme en mono-entité natif ; le bloquer casserait les installs en cours
			// de configuration). Ce N'EST PAS un fail-open : c'est le défaut système.
			dol_syslog(
				__METHOD__ . " fk_user=" . $fk_user
					. " sans affectation — entite 1 (retrocompat mono-entite, decision produit)",
				LOG_INFO
			);
			return array(1);
		}

		// --- Entités actives (source unique) ---
		$activeEntities = $this->listEntities(true);

		if ($activeEntities === -1) {
			// Erreur SQL — FAIL-CLOSED : scope vide (cf. ci-dessus)
			dol_syslog(
				__METHOD__ . " Erreur SQL listEntities fk_user=" . $fk_user
					. " — FAIL-CLOSED (scope vide)",
				LOG_ERR
			);
			return array();
		}

		// Construire un set des entity_id actifs pour intersection O(n) efficace
		$activeIds = array();
		foreach ($activeEntities as $ent) {
			$activeIds[(int) $ent->entity_id] = true;
		}

		// Intersection : entités affectées AU user ∩ entités actives (corrige F-12)
		$allowed = array();
		foreach ($userRows as $row) {
			$eid = (int) $row->entity_id;
			if (isset($activeIds[$eid])) {
				$allowed[] = $eid;
			}
		}

		if (empty($allowed)) {
			// Affectations présentes mais TOUTES sur des entités inactives.
			// FAIL-CLOSED : scope vide, AUCUN octroi de l'entité 1 (le user n'y est pas
			// affecté → ne pas lui ouvrir l'entité principale). Le trigger ne posera rien ;
			// la garde 3.4 confirmera l'absence d'accès.
			dol_syslog(
				__METHOD__ . " fk_user=" . $fk_user
					. " : toutes les entites affectees sont inactives — FAIL-CLOSED (scope vide)",
				LOG_WARNING
			);
			return array();
		}

		// Tri croissant par entity_id (déterministe, utilisé par la résolution du défaut)
		sort($allowed, SORT_NUMERIC);

		dol_syslog(
			__METHOD__ . " fk_user=" . $fk_user
				. " entites_autorisees=" . implode(',', $allowed),
			LOG_INFO
		);

		return $allowed;
	}

	/**
	 * Retourne l'entité par défaut d'un utilisateur actif.
	 *
	 * Cherche l'entrée is_default=1 dans llx_multientity_user_entity pour un
	 * utilisateur actif.
	 *
	 * Contrat (FAIL-CLOSED) :
	 *   - retourne l'entity_id par défaut (>0) si trouvé ;
	 *   - retourne **0** (sentinel « pas de défaut ») si aucun is_default OU erreur SQL.
	 * L'appelant NE doit PAS interpréter 0 comme l'entité 1 : il valide le défaut
	 * contre getAllowedEntities() et retombe sur la 1re autorisée si 0/∉ autorisées.
	 *
	 * @param int $fk_user Identifiant de l'utilisateur.
	 * @return int entity_id par défaut (>0), ou 0 si indéterminé/erreur.
	 */
	public function getDefaultEntity($fk_user)
	{
		$fk_user = (int) $fk_user;

		$sql = "SELECT ue.entity_id";
		$sql .= " FROM " . MAIN_DB_PREFIX . "multientity_user_entity ue";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = ue.fk_user AND u.statut = 1";
		$sql .= " WHERE ue.fk_user = " . $fk_user . " AND ue.is_default = 1";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			// Erreur SQL : journaliser puis sentinel 0 (PAS 1 — ne pas octroyer d'entité)
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__ . " Erreur SQL fk_user=" . $fk_user . " : " . $this->error, LOG_ERR);
			return 0;
		}
		if ($this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			return (int) $obj->entity_id;
		}
		$this->db->free($resql);

		// Aucun is_default configuré : sentinel 0 (l'appelant prendra la 1re autorisée)
		return 0;
	}
}
