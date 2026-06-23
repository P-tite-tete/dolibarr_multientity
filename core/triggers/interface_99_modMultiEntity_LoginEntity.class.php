<?php
/**
 * @file        core/triggers/interface_99_modMultiEntity_LoginEntity.class.php
 * @brief       Trigger USER_LOGIN — résolution de l'entité d'ouverture de session.
 *
 * Réagit uniquement à l'action USER_LOGIN (AC#1, AC#6).
 * Pose $_SESSION['dol_entity'] sur l'entité autoritaire déterminée par
 * Multientity::getAllowedEntities() ∩ getDefaultEntity() (AC#4, AC#5).
 *
 * ⚠️ SÉCURITÉ D'ISOLATION (AC#7) :
 *   - Ne jamais poser une entité ∉ getAllowedEntities().
 *   - getAllowedEntities() est la SEULE autorité ; le cache session
 *     $_SESSION['multientity_allowed_entities'] est de la PERFORMANCE uniquement —
 *     les stories 3.3 (contrôle serveur) et 3.4 (garde) doivent revalider via
 *     getAllowedEntities() (requête serveur), jamais via ce tableau.
 *
 * ⚠️ ROBUSTESSE (AC#10) :
 *   - Exception ou erreur service → fallback silencieux entité 1 + LOG_WARNING.
 *   - Module désactivé → trigger absent → $_SESSION non posée → $conf->entity=1 natif.
 *
 * @package     MultiEntity
 * @subpackage  Triggers
 * @category    triggers
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 * @link        http://www.dolibarr.org
 * @link        https://www.ptitetete.org
 */

/**
 * Classe du trigger de résolution d'entité au login.
 *
 * Étend DolibarrTriggers selon le pattern Dolibarr standard.
 * Toute action autre que USER_LOGIN est ignorée (return 0).
 */
class InterfaceLoginEntity extends DolibarrTriggers
{
	/**
	 * @var DoliDB $db Handler base de données
	 */
	public $db;

	/**
	 * @var string $name Nom interne du trigger
	 */
	public $name = 'ModMultiEntity_LoginEntity';

	/**
	 * @var string $description Description du trigger
	 */
	public $description = "Résolution de l'entité d'ouverture de session (USER_LOGIN) — module MultiEntity";

	/**
	 * @var string $version Version du trigger ('dolibarr' = version du core ou version fixe)
	 */
	public $version = '0.1.0';

	/**
	 * @var string $picto Picto du trigger (icône générique)
	 */
	public $picto = 'building';

	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Handler base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
		parent::__construct($db);
	}

	/**
	 * Traite les déclencheurs.
	 *
	 * Seule l'action USER_LOGIN est traitée (AC#6) ; toutes les autres
	 * actions retournent 0 immédiatement sans effet de bord.
	 *
	 * Résolution de l'entité d'ouverture (AC#4) :
	 *   1. getAllowedEntities($user_id) → liste autoritaire (jamais vide, au pire {1}).
	 *   2. getDefaultEntity($user_id) → entité par défaut configurée.
	 *   3. Si default ∈ allowed → poser default ; sinon → poser min(allowed).
	 *
	 * Sécurité : aucune entité hors allowed n'est jamais posée (AC#7).
	 * Cache session : multientity_allowed_entities = performance uniquement (AC#8).
	 *
	 * @param string $action Code de l'action déclenchée
	 * @param object $object Objet Dolibarr concerné (User au login)
	 * @param User   $user   Utilisateur exécutant l'action
	 * @param Translate $langs Gestionnaire de traductions
	 * @param Conf   $conf   Configuration Dolibarr
	 * @return int 1 si USER_LOGIN traité avec succès, 0 si action ignorée ou fallback,
	 *             <0 en cas d'erreur non rattrapée (ne devrait jamais survenir avec le try/catch).
	 */
	public function runTrigger($action, $object, User $user, $langs, $conf)
	{
		// --- Garde d'entrée (AC#6) --- retour immédiat si action non concernée ou objet invalide
		if ($action !== 'USER_LOGIN') {
			return 0;
		}

		if (!($object instanceof User) || (int) $object->id <= 0) {
			dol_syslog(
				__METHOD__ . " USER_LOGIN : objet invalide ou id<=0 — trigger ignore",
				LOG_WARNING
			);
			return 0;
		}

		$userId = (int) $object->id;

		try {
			// Chargement du service (source unique de l'isolation — AC#3)
			dol_include_once('/multientity/class/multientity.class.php');
			$service = new Multientity($this->db);

			// Entités autorisées — SEULE autorité (AC#3, AC#7)
			$allowed = $service->getAllowedEntities($userId);

			// FAIL-CLOSED : scope vide = erreur technique ou toutes entités inactives.
			// On NE POSE AUCUNE entité (pas d'octroi de l'entité 1) ; on retire toute valeur
			// résiduelle de session pour ne pas laisser un scope obsolète. La garde 3.4
			// (à venir) confirmera l'absence d'accès. LOG_ERR (distinct d'une résolution OK).
			if (empty($allowed)) {
				unset($_SESSION['dol_entity']);
				unset($_SESSION['multientity_allowed_entities']);
				dol_syslog(
					__METHOD__ . " USER_LOGIN user=" . $userId
						. " : aucune entite autorisee (erreur ou toutes inactives)"
						. " — FAIL-CLOSED, aucune entite posee",
					LOG_ERR
				);
				return 0;
			}

			// Entité par défaut configurée par l'admin (0 = indéterminé)
			$default = (int) $service->getDefaultEntity($userId);

			// Résolution : default ∈ allowed → ok ; sinon → première autorisée (AC#4)
			// Sécurité : on ne pose JAMAIS une entité hors $allowed (AC#7)
			if (in_array($default, $allowed, true)) {
				$resolvedEntity = $default;
			} else {
				$resolvedEntity = (int) min($allowed);
				// $default = 0 = sentinel « pas de défaut configuré » (cas légitime) → pas de WARNING.
				// $default > 0 hors autorisées = vraie incohérence de données → WARNING d'audit (EC6).
				if ($default > 0) {
					dol_syslog(
						__METHOD__ . " user=" . $userId
							. " : entite_defaut=" . $default . " hors autorisees ["
							. implode(',', $allowed) . "]"
							. " — premiere autorisee=" . $resolvedEntity . " retenue",
						LOG_WARNING
					);
				}
			}

			// --- Pose de la session (AC#5) ---
			// master.inc.php lit $_SESSION['dol_entity'] à la requête suivante
			// et en déduit $conf->entity (comportement natif, zéro patch core).
			$_SESSION['dol_entity'] = $resolvedEntity;

			// --- Cache session NON AUTORITAIRE (AC#8 — performance uniquement) ---
			// ⚠️ Ce tableau NE FAIT PAS AUTORITÉ. Stories 3.3 et 3.4 DOIVENT revalider
			// via Multientity::getAllowedEntities() (requête serveur). Ne jamais autoriser
			// un switch ou un accès sur la seule base de ce tableau (risque session forgée).
			// Éléments de type int : les consommateurs doivent caster en (int) avant in_array strict.
			$_SESSION['multientity_allowed_entities'] = $allowed;

			// --- Journalisation d'audit (AC#9) ---
			// IP validée (anti log-injection via REMOTE_ADDR mal formé en config proxy)
			$remoteIp = filter_var(
				isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
				FILTER_VALIDATE_IP
			);
			if ($remoteIp === false) {
				$remoteIp = 'invalid';
			}
			// Traçabilité impersonation : si l'exécutant diffère de l'utilisateur connecté
			$impersonation = ((int) $user->id !== $userId) ? " par_user=" . (int) $user->id . " [impersonation]" : "";
			dol_syslog(
				__METHOD__ . " USER_LOGIN user=" . $userId
					. " entity=" . $resolvedEntity
					. " allowed=[" . implode(',', $allowed) . "]"
					. " ip=" . $remoteIp . $impersonation,
				LOG_INFO
			);

			return 1;

		} catch (Exception $e) {
			// --- Robustesse FAIL-CLOSED (AC#10) ---
			// Ne jamais bloquer le login (un bug ne doit pas verrouiller l'instance),
			// MAIS ne JAMAIS octroyer une entité concrète sur exception : on retire toute
			// valeur de session pour ne pas laisser un scope obsolète/forgé. Le core appliquera
			// son défaut natif ; la garde 3.4 fermera. LOG_ERR (incident à remonter), distinct
			// d'une résolution réussie.
			unset($_SESSION['dol_entity']);
			unset($_SESSION['multientity_allowed_entities']);
			dol_syslog(
				__METHOD__ . " USER_LOGIN user=" . $userId
					. " : exception catchee — FAIL-CLOSED, aucune entite posee"
					. " | message=" . $e->getMessage(),
				LOG_ERR
			);
			return 0;
		}
	}
}
