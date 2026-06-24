<?php
/**
 * @file        core/hookcontrols/actions_multientity.class.php
 * @brief       Hook handler UI du module MultiEntity — indicateur d'entité
 *              courante + sélecteur dans la barre supérieure Dolibarr.
 *
 * Injecte via le hook `printTopRightMenu` (contexte `toprightmenu`) :
 *  - Indicateur permanent de l'entité courante (label + pastille couleur).
 *  - Sélecteur d'entités si l'utilisateur en possède plus d'une autorisée.
 *
 * IMPORTANT (sécurité) : ce composant affiche UNIQUEMENT les entités
 * retournées par getAllowedEntities() (requête serveur). Il ne sécurise PAS
 * le switch lui-même ; la validation serveur est déléguée à la story 3.3.
 * Une URL forgée `?switchentity=N` reste possible tant que 3.3/3.4/3.5
 * ne sont pas livrés — ce module NE doit PAS être considéré livrable sans eux.
 *
 * @package     MultiEntity
 * @subpackage  HookControls
 * @category    hookcontrols
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 * @link        http://www.dolibarr.org
 * @link        https://www.ptitetete.org
 */

/**
 * Classe hook handler MultiEntity — barre supérieure Dolibarr.
 */
class ActionsMultientity
{
	/** @var DoliDB $db Handler base de données */
	public $db;

	/** @var string $resprints HTML à injecter dans la zone hook (concaténé par le core) */
	public $resprints = '';

	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Handler base de données Dolibarr
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook `printTopRightMenu` — injecte l'indicateur d'entité courante
	 * et, si l'utilisateur a plus d'une entité autorisée, le sélecteur.
	 *
	 * Contrat :
	 *  - Aucun affichage si utilisateur non authentifié.
	 *  - Indicateur toujours affiché (label + pastille) dès authentification.
	 *  - Sélecteur affiché ssi count(getAllowedEntities) > 1.
	 *  - Liste = getAllowedEntities() (requête serveur — autorité, NFR-P1 : 1×/page).
	 *  - Si getAllowedEntities() retourne array() vide (fail-closed) → indicateur seul.
	 *  - Entité courante hors $allowed → indicateur affiché mais non inclus dans le
	 *    sélecteur (incohérence de session gérée par 3.4).
	 *  - Couleur validée /^#[0-9A-Fa-f]{6}$/ avant injection CSS (anti-XSS).
	 *  - Labels échappés via dol_escape_htmltag.
	 *
	 * @param array  $parameters  Paramètres fournis par le core (context, etc.)
	 * @param object &$object     Objet courant de la page (passé par référence)
	 * @param string &$action     Action courante (passé par référence)
	 * @param object $hookmanager Gestionnaire de hooks Dolibarr
	 * @return int 0 (le core continue l'exécution des autres hooks)
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf, $langs;

		$langs->load('multientity@multientity');

		// --- Garde : utilisateur authentifié uniquement ---
		if (empty($user->id) || (int) $user->id <= 0) {
			return 0;
		}

		// --- Chargement du service (une seule fois par page, NFR-P1) ---
		dol_include_once('/multientity/class/multientity.class.php');
		if (!class_exists('Multientity')) {
			// Installation partielle : ne pas tuer la page (Fatal Error)
			dol_syslog(__METHOD__ . " classe Multientity introuvable — composant ignoré", LOG_ERR);
			return 0;
		}
		$service = new Multientity($this->db);

		// --- Résolution de l'entité courante ---
		$currentId = (int) $conf->entity;
		$currentObj = $service->getEntity($currentId);
		// Repli si null (entité absente) ou erreur SQL (-1)
		if (!is_object($currentObj)) {
			$currentObj = null;
		}

		// Label courant échappé
		if ($currentObj !== null) {
			$currentLabel = dol_escape_htmltag($currentObj->label);
		} else {
			$currentLabel = dol_escape_htmltag('Entity #' . $currentId);
		}

		// Couleur courante (validée avant injection)
		$currentColor = '';
		if (
			$currentObj !== null
			&& !empty($currentObj->color)
			&& preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $currentObj->color)
		) {
			$currentColor = (string) $currentObj->color;
		}

		// --- Entités autorisées (autorité serveur — getAllowedEntities = source unique) ---
		$allowed = $service->getAllowedEntities((int) $user->id);
		if (!is_array($allowed)) {
			$allowed = array();
		}

		// --- Construction HTML ---
		$out = '<div class="multientity-indicator" style="display:inline-block;padding:0 8px;vertical-align:middle;">';

		// Pastille couleur courante (si définie)
		$badgeHtml = '';
		if ($currentColor !== '') {
			$badgeHtml = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;'
				. 'background:' . $currentColor . ';margin-right:4px;vertical-align:middle;" '
				. 'aria-hidden="true"></span>';
		}

		// Préfixe traduit + label
		$prefix = dol_escape_htmltag($langs->trans('CurrentEntity'));

		$out .= '<span style="font-size:0.9em;color:#666;">' . $prefix . ' : </span>';
		$out .= $badgeHtml;
		$out .= '<strong>' . $currentLabel . '</strong>';

		// --- Sélecteur (ssi > 1 entité autorisée) ---
		if (count($allowed) > 1) {
			$out .= ' <span style="font-size:0.85em;">[';
			$out .= dol_escape_htmltag($langs->trans('SwitchEntity')) . ' : ';

			$first = true;
			foreach ($allowed as $eid) {
				$eid = (int) $eid;
				// NFR-P1 : réutiliser l'objet déjà chargé pour l'entité courante (évite N+1)
				if ($eid === $currentId && $currentObj !== null) {
					$entObj = $currentObj;
				} else {
					$entObj = $service->getEntity($eid);
				}
				if (!is_object($entObj)) {
					// Entité non trouvée (incohérence rare) — on saute
					continue;
				}

				$entLabel = dol_escape_htmltag($entObj->label);

				// Pastille couleur de l'entité de la liste
				$entBadge = '';
				if (
					!empty($entObj->color)
					&& preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $entObj->color)
				) {
					$entBadge = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;'
						. 'background:' . (string) $entObj->color . ';margin-right:3px;vertical-align:middle;" '
						. 'aria-hidden="true"></span>';
				}

				// Séparateur
				if (!$first) {
					$out .= ' | ';
				}
				$first = false;

				// Lien de switch — URL relative simple (LOW-2 : pas de dol_buildpath sans 2e arg)
				// HIGH-2 : pas de token/nonce ici (GET, validation serveur déléguée à 3.3)
				$switchUrl = '?switchentity=' . $eid;

				if ($eid === $currentId) {
					// Entité courante → non cliquable, mise en évidence
					$out .= '<strong>' . $entBadge . $entLabel . '</strong>';
				} else {
					$out .= '<a href="' . dol_escape_htmltag($switchUrl) . '" style="text-decoration:none;">'
						. $entBadge . $entLabel . '</a>';
				}
			}

			$out .= ']</span>';
		}

		$out .= '</div>';

		$this->resprints = $out;

		return 0;
	}
}
