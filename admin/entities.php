<?php
/**
 * @file        admin/entities.php
 * @brief       Page d'administration CRUD des entités MultiEntity
 *
 * @package     MultiEntity
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 */

// Load Dolibarr environment (__DIR__ based, compatible Docker/Cloudron/symlink)
$res = false;
if (!$res && file_exists(__DIR__ . "/../../../main.inc.php")) {
	$res = @include __DIR__ . "/../../../main.inc.php";
}
if (!$res && file_exists(__DIR__ . "/../../../../main.inc.php")) {
	$res = @include __DIR__ . "/../../../../main.inc.php";
}
if (!$res && !empty($_SERVER['DOCUMENT_ROOT']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/main.inc.php')) {
	$res = @include $_SERVER['DOCUMENT_ROOT'] . '/main.inc.php';
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/multientity/lib/multientity.lib.php');
dol_include_once('/multientity/class/multientity.class.php');

// Traductions
$langs->loadLangs(array("admin", "multientity@multientity"));

// Sécurité : super-admin uniquement
if (!$user->admin) {
	accessforbidden();
}

$action    = GETPOST('action', 'alphanohtml');
$entity_id = GETPOSTINT('entity_id');

$service = new Multientity($db);

// URL de la page échappée pour les sorties HTML (anti-XSS réfléchi via PATH_INFO sur PHP_SELF)
$selfUrl = dol_escape_htmltag($_SERVER['PHP_SELF']);

/*
 * Actions POST
 */

if (in_array($action, array('create', 'update', 'setactive', 'company'))) {
	if (GETPOST('token', 'alphanohtml') != newToken()) {
		accessforbidden('Invalid CSRF token');
	}
}

if ($action == 'create') {
	$label      = GETPOST('label', 'alphanohtml');
	$code       = GETPOST('code', 'alphanohtml');
	$color      = GETPOST('color', 'alphanohtml');
	$companyNom = GETPOST('company_nom', 'alphanohtml');
	$companyAdr = GETPOST('company_address', 'alphanohtml');
	$companyZip = GETPOST('company_zip', 'alphanohtml');
	$companyTown = GETPOST('company_town', 'alphanohtml');
	$countryId  = GETPOSTINT('country_id');
	$currency   = GETPOST('currency', 'aZ09');

	// Validations côté page AVANT appel service (AC#10)
	$error = 0;
	if (empty(trim((string) $label))) {
		setEventMessages($langs->trans("LabelRequired"), null, 'errors');
		$error++;
	}
	if (!$error && !empty($color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
		setEventMessages($langs->trans("InvalidColorFormat"), null, 'errors');
		$error++;
	}

	// Validation pays (anti-injection — reconstruit depuis la base, jamais depuis POST)
	$countryValue = '';
	if (!$error && $countryId > 0) {
		$sqlCountry = "SELECT rowid, code, label FROM " . MAIN_DB_PREFIX . "c_country WHERE rowid = " . (int) $countryId;
		$resCountry = $db->query($sqlCountry);
		if ($resCountry && $db->num_rows($resCountry) > 0) {
			$objCountry = $db->fetch_object($resCountry);
			$db->free($resCountry);
			$countryValue = $objCountry->rowid . ':' . $objCountry->code . ':' . $objCountry->label;
		} elseif ($resCountry) {
			$db->free($resCountry);
			dol_syslog('admin/entities.php create : country_id=' . $countryId . ' introuvable', LOG_WARNING);
			$countryValue = '';
		}
	}

	// Validation devise (anti-injection — validée contre llx_currency active)
	$currencyValue = '';
	if (!$error && !empty($currency)) {
		$sqlCurr = "SELECT code_iso FROM " . MAIN_DB_PREFIX . "currency WHERE active = 1 AND code_iso = '" . $db->escape($currency) . "'";
		$resCurr = $db->query($sqlCurr);
		if ($resCurr && $db->num_rows($resCurr) > 0) {
			$objCurr = $db->fetch_object($resCurr);
			$db->free($resCurr);
			$currencyValue = $objCurr->code_iso;
		} elseif ($resCurr) {
			$db->free($resCurr);
			dol_syslog('admin/entities.php create : devise ' . $currency . ' invalide ou inactive', LOG_WARNING);
			$currencyValue = '';
		}
	}

	if (!$error) {
		$colorArg = (empty($color) ? null : $color);
		$codeArg  = (empty($code) ? null : $code);
		$newEntityId = $service->createEntity($label, $codeArg, $colorArg);
		if ($newEntityId > 0) {
			setEventMessages($langs->trans("EntityCreated"), null, 'mesgs');

			// Pose des constantes société — échec non bloquant (entité déjà committée)
			$params = array();
			if (trim((string) $companyNom) !== '') {
				$params['MAIN_INFO_SOCIETE_NOM'] = $companyNom;
			}
			if (trim((string) $companyAdr) !== '') {
				$params['MAIN_INFO_SOCIETE_ADDRESS'] = $companyAdr;
			}
			if (trim((string) $companyZip) !== '') {
				$params['MAIN_INFO_SOCIETE_ZIP'] = $companyZip;
			}
			if (trim((string) $companyTown) !== '') {
				$params['MAIN_INFO_SOCIETE_TOWN'] = $companyTown;
			}
			if ($countryValue !== '') {
				$params['MAIN_INFO_SOCIETE_COUNTRY'] = $countryValue;
			}
			if ($currencyValue !== '') {
				$params['MAIN_MONNAIE'] = $currencyValue;
			}

			if (!empty($params)) {
				$initRes = $service->initEntityConstants($newEntityId, $params);
				if ($initRes < 0) {
					setEventMessages($langs->trans("CompanyInfoSaved") . ' — ' . $langs->trans("Warning") . ': ' . $service->error, null, 'warnings');
				}
			}
		} else {
			setEventMessages($service->error, $service->errors, 'errors');
		}
	}

	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'update') {
	$label = GETPOST('label', 'alphanohtml');
	$code  = GETPOST('code', 'alphanohtml');
	$color = GETPOST('color', 'alphanohtml');

	// Vérif existence AVANT tout UPDATE (AC#6)
	$existing = $service->getEntity($entity_id);
	if (!is_object($existing)) {
		setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	// Validations côté page AVANT appel service (AC#10)
	$error = 0;
	if (empty(trim((string) $label))) {
		setEventMessages($langs->trans("LabelRequired"), null, 'errors');
		$error++;
	}
	if (!$error && !empty($color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
		setEventMessages($langs->trans("InvalidColorFormat"), null, 'errors');
		$error++;
	}

	if (!$error) {
		$colorArg = (empty($color) ? null : $color);
		$codeArg  = (empty($code) ? null : $code);
		$result   = $service->updateEntity($entity_id, $label, $codeArg, $colorArg);
		if ($result > 0) {
			setEventMessages($langs->trans("EntityUpdated"), null, 'mesgs');
		} else {
			setEventMessages($service->error, $service->errors, 'errors');
			$error++;
		}
	}

	// En cas d'erreur, revenir au formulaire d'édition pré-rempli (préserve le contexte)
	$redirect = $_SERVER['PHP_SELF'] . ($error ? '?action=edit&entity_id=' . (int) $entity_id : '');
	header('Location: ' . $redirect);
	exit;
}

if ($action == 'setactive') {
	$active = (GETPOSTINT('active') ? 1 : 0);

	// Vérif existence AVANT setActive (AC#6)
	$existing = $service->getEntity($entity_id);
	if (!is_object($existing)) {
		setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	$result = $service->setActive($entity_id, (bool) $active);
	if ($result < 0) {
		setEventMessages($langs->trans("CannotDeactivateMainEntity"), $service->errors, 'errors');
	} elseif ($active) {
		setEventMessages($langs->trans("EntityActivated"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("EntityDisabled"), null, 'mesgs');
	}

	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'company') {
	// Vérification existence entité (AC#8)
	$existing = $service->getEntity($entity_id);
	if (!is_object($existing)) {
		setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	$companyNom  = GETPOST('company_nom', 'alphanohtml');
	$companyAdr  = GETPOST('company_address', 'alphanohtml');
	$companyZip  = GETPOST('company_zip', 'alphanohtml');
	$companyTown = GETPOST('company_town', 'alphanohtml');
	$countryId   = GETPOSTINT('country_id');
	$currency    = GETPOST('currency', 'aZ09');

	// Validation pays — reconstruit depuis la base (AC#8)
	$countryValue = '';
	if ($countryId > 0) {
		$sqlCountry = "SELECT rowid, code, label FROM " . MAIN_DB_PREFIX . "c_country WHERE rowid = " . (int) $countryId;
		$resCountry = $db->query($sqlCountry);
		if ($resCountry && $db->num_rows($resCountry) > 0) {
			$objCountry = $db->fetch_object($resCountry);
			$db->free($resCountry);
			$countryValue = $objCountry->rowid . ':' . $objCountry->code . ':' . $objCountry->label;
		} elseif ($resCountry) {
			$db->free($resCountry);
			dol_syslog('admin/entities.php company : country_id=' . $countryId . ' introuvable', LOG_WARNING);
			setEventMessages($langs->trans("InvalidCountrySelection"), null, 'warnings');
		}
	}

	// Validation devise (AC#8)
	$currencyValue = '';
	if (!empty($currency)) {
		$sqlCurr = "SELECT code_iso FROM " . MAIN_DB_PREFIX . "currency WHERE active = 1 AND code_iso = '" . $db->escape($currency) . "'";
		$resCurr = $db->query($sqlCurr);
		if ($resCurr && $db->num_rows($resCurr) > 0) {
			$objCurr = $db->fetch_object($resCurr);
			$db->free($resCurr);
			$currencyValue = $objCurr->code_iso;
		} elseif ($resCurr) {
			$db->free($resCurr);
			dol_syslog('admin/entities.php company : devise ' . $currency . ' invalide ou inactive', LOG_WARNING);
		}
	}

	$params = array();
	if (trim((string) $companyNom) !== '') {
		$params['MAIN_INFO_SOCIETE_NOM'] = $companyNom;
	}
	if (trim((string) $companyAdr) !== '') {
		$params['MAIN_INFO_SOCIETE_ADDRESS'] = $companyAdr;
	}
	if (trim((string) $companyZip) !== '') {
		$params['MAIN_INFO_SOCIETE_ZIP'] = $companyZip;
	}
	if (trim((string) $companyTown) !== '') {
		$params['MAIN_INFO_SOCIETE_TOWN'] = $companyTown;
	}
	if ($countryValue !== '') {
		$params['MAIN_INFO_SOCIETE_COUNTRY'] = $countryValue;
	}
	if ($currencyValue !== '') {
		$params['MAIN_MONNAIE'] = $currencyValue;
	}

	if (!empty($params)) {
		$initRes = $service->initEntityConstants($entity_id, $params);
		if ($initRes >= 0) {
			setEventMessages($langs->trans("CompanyInfoSaved"), null, 'mesgs');
		} else {
			setEventMessages($service->error, $service->errors, 'errors');
		}
	}

	// Conserver le contexte pour réafficher le formulaire société pré-rempli (AC#6)
	header('Location: ' . $_SERVER['PHP_SELF'] . '?action=company&entity_id=' . (int) $entity_id);
	exit;
}

/*
 * View
 */

$form = new Form($db);

// Pré-chargement en mode édition (AC — formulaire d'édition pré-rempli)
$editEntity = null;
if ($action == 'edit') {
	if ($entity_id <= 0) {
		setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
	} else {
		$editEntity = $service->getEntity($entity_id);
		if (!is_object($editEntity)) {
			setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
			$editEntity = null;
		}
	}
}

// Pré-chargement en mode société (AC#6 — formulaire company pré-rempli)
$companyEntity = null;
$companyConsts = array();
if ($action == 'company') {
	if ($entity_id <= 0) {
		setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
	} else {
		$companyEntity = $service->getEntity($entity_id);
		if (is_object($companyEntity)) {
			$companyConsts = $service->getEntityConstants($entity_id);
			if (!is_array($companyConsts)) {
				$companyConsts = array();
			}
		} else {
			$companyEntity = null;
			setEventMessages($langs->trans("EntityNotFound"), null, 'errors');
		}
	}
}

llxHeader('', $langs->trans("Entities") . ' — ' . $langs->trans("MultiEntity"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("MultiEntitySetup"), $linkback, 'building');

$head = multientityAdminPrepareHead();
print dol_get_fiche_head($head, 'entities', $langs->trans("MultiEntity"), -1, 'building');

// -------------------------------------------------------------------------
// Liste des entités
// -------------------------------------------------------------------------
$entities = $service->listEntities();
if ($entities === -1) {
	setEventMessages($service->error, $service->errors, 'errors');
	$entities = array();
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Id") . '</td>';
print '<td>' . $langs->trans("EntityLabel") . '</td>';
print '<td>' . $langs->trans("EntityCode") . '</td>';
print '<td>' . $langs->trans("EntityColor") . '</td>';
print '<td>' . $langs->trans("Status") . '</td>';
print '<td class="center">' . $langs->trans("Actions") . '</td>';
print '</tr>';

if (is_array($entities) && count($entities) > 0) {
	foreach ($entities as $ent) {
		print '<tr class="oddeven">';

		// entity_id
		print '<td>' . (int) $ent->entity_id . '</td>';

		// label
		print '<td>' . dol_escape_htmltag($ent->label) . '</td>';

		// code
		print '<td>' . dol_escape_htmltag((string) $ent->code) . '</td>';

		// pastille couleur — validation regex en SORTIE (anti-injection CSS/XSS sur attribut style)
		print '<td>';
		if (!empty($ent->color) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $ent->color)) {
			print '<span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:' . $ent->color . ';border:1px solid #999;vertical-align:middle;margin-right:4px;"></span>';
			print dol_escape_htmltag($ent->color);
		} else {
			print '—';
		}
		print '</td>';

		// statut
		print '<td>';
		if ($ent->active) {
			print '<span class="badge badge-status4 badge-status">' . $langs->trans("EntityStatusActive") . '</span>';
		} else {
			print '<span class="badge badge-status0 badge-status">' . $langs->trans("EntityStatusInactive") . '</span>';
		}
		print '</td>';

		// actions
		print '<td class="center">';

		// bouton Modifier
		print '<a class="butActionSmall" href="' . $selfUrl . '?action=edit&entity_id=' . (int) $ent->entity_id . '">' . $langs->trans("Modify") . '</a> ';

		// bouton Société (AC#6)
		print '<a class="butActionSmall" href="' . $selfUrl . '?action=company&entity_id=' . (int) $ent->entity_id . '">' . dol_escape_htmltag($langs->trans("CompanyInfo")) . '</a> ';

		// bouton Activer/Désactiver — PAS de désactivation pour entity_id=1 (AC#6)
		if ($ent->active) {
			if ((int) $ent->entity_id !== 1) {
				print '<form method="POST" action="' . $selfUrl . '" style="display:inline">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<input type="hidden" name="action" value="setactive">';
				print '<input type="hidden" name="entity_id" value="' . (int) $ent->entity_id . '">';
				print '<input type="hidden" name="active" value="0">';
				print '<input type="submit" class="butActionSmallDelete" value="' . dol_escape_htmltag($langs->trans("Disable")) . '">';
				print '</form>';
			}
		} else {
			print '<form method="POST" action="' . $selfUrl . '" style="display:inline">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="setactive">';
			print '<input type="hidden" name="entity_id" value="' . (int) $ent->entity_id . '">';
			print '<input type="hidden" name="active" value="1">';
			print '<input type="submit" class="butActionSmall" value="' . dol_escape_htmltag($langs->trans("Enable")) . '">';
			print '</form>';
		}

		print '</td>';
		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="6" class="center">' . $langs->trans("NoRecordFound") . '</td></tr>';
}

print '</table>';
print '<br>';

// -------------------------------------------------------------------------
// Formulaire d'édition (pré-rempli) — affiché si action=edit
// -------------------------------------------------------------------------
if (!empty($editEntity) && is_object($editEntity)) {
	print '<div class="div-table-responsive-no-min">';
	print '<h2>' . $langs->trans("EditEntity") . ' #' . (int) $editEntity->entity_id . '</h2>';
	print '<form method="POST" action="' . $selfUrl . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="entity_id" value="' . (int) $editEntity->entity_id . '">';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("EditEntity") . '</td></tr>';

	// Label
	print '<tr class="oddeven">';
	print '<td><label for="edit_label">' . $langs->trans("EntityLabel") . ' <span class="fieldrequired">*</span></label></td>';
	print '<td><input type="text" id="edit_label" name="label" class="minwidth200" value="' . dol_escape_htmltag((string) $editEntity->label) . '" required></td>';
	print '</tr>';

	// Code
	print '<tr class="oddeven">';
	print '<td><label for="edit_code">' . $langs->trans("EntityCode") . '</label></td>';
	print '<td><input type="text" id="edit_code" name="code" class="minwidth100" value="' . dol_escape_htmltag((string) $editEntity->code) . '"></td>';
	print '</tr>';

	// Couleur
	print '<tr class="oddeven">';
	print '<td><label for="edit_color">' . $langs->trans("EntityColor") . ' <span class="opacitymedium">(#RRGGBB)</span></label></td>';
	print '<td><input type="text" id="edit_color" name="color" class="minwidth100" placeholder="#RRGGBB" value="' . dol_escape_htmltag((string) $editEntity->color) . '"></td>';
	print '</tr>';

	print '</table>';
	print '<div class="center"><br>';
	print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
	print ' &nbsp; <a class="button button-cancel" href="' . $selfUrl . '">' . $langs->trans("Cancel") . '</a>';
	print '</div>';
	print '</form>';
	print '</div>';
	print '<br>';
}

// -------------------------------------------------------------------------
// Formulaire société (pré-rempli) — affiché si action=company
// -------------------------------------------------------------------------
if (!empty($companyEntity) && is_object($companyEntity)) {
	// Extraire le rowid pays depuis la valeur stockée 'rowid:CODE:Label'
	$storedCountry = isset($companyConsts['MAIN_INFO_SOCIETE_COUNTRY']) ? $companyConsts['MAIN_INFO_SOCIETE_COUNTRY'] : '';
	$selectedCountryId = 0;
	if (!empty($storedCountry)) {
		$parts = explode(':', $storedCountry, 3);
		if (isset($parts[0]) && (int) $parts[0] > 0) {
			$selectedCountryId = (int) $parts[0];
		}
	}
	$storedCurrency = isset($companyConsts['MAIN_MONNAIE']) ? $companyConsts['MAIN_MONNAIE'] : '';

	print '<div class="div-table-responsive-no-min">';
	print '<h2>' . dol_escape_htmltag($langs->trans("CompanyInfo")) . ' — ' . dol_escape_htmltag($companyEntity->label) . ' (#' . (int) $companyEntity->entity_id . ')</h2>';
	print '<form method="POST" action="' . $selfUrl . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="company">';
	print '<input type="hidden" name="entity_id" value="' . (int) $companyEntity->entity_id . '">';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">' . dol_escape_htmltag($langs->trans("CompanyInfo")) . '</td></tr>';

	// Nom société
	print '<tr class="oddeven">';
	print '<td><label for="cmp_nom">' . dol_escape_htmltag($langs->trans("CompanyName")) . '</label></td>';
	print '<td><input type="text" id="cmp_nom" name="company_nom" class="minwidth200" value="' . dol_escape_htmltag(isset($companyConsts['MAIN_INFO_SOCIETE_NOM']) ? $companyConsts['MAIN_INFO_SOCIETE_NOM'] : '') . '"></td>';
	print '</tr>';

	// Adresse
	print '<tr class="oddeven">';
	print '<td><label for="cmp_adr">' . dol_escape_htmltag($langs->trans("CompanyAddress")) . '</label></td>';
	print '<td><input type="text" id="cmp_adr" name="company_address" class="minwidth300" value="' . dol_escape_htmltag(isset($companyConsts['MAIN_INFO_SOCIETE_ADDRESS']) ? $companyConsts['MAIN_INFO_SOCIETE_ADDRESS'] : '') . '"></td>';
	print '</tr>';

	// Code postal
	print '<tr class="oddeven">';
	print '<td><label for="cmp_zip">' . dol_escape_htmltag($langs->trans("CompanyZip")) . '</label></td>';
	print '<td><input type="text" id="cmp_zip" name="company_zip" class="minwidth100" value="' . dol_escape_htmltag(isset($companyConsts['MAIN_INFO_SOCIETE_ZIP']) ? $companyConsts['MAIN_INFO_SOCIETE_ZIP'] : '') . '"></td>';
	print '</tr>';

	// Ville
	print '<tr class="oddeven">';
	print '<td><label for="cmp_town">' . dol_escape_htmltag($langs->trans("CompanyTown")) . '</label></td>';
	print '<td><input type="text" id="cmp_town" name="company_town" class="minwidth200" value="' . dol_escape_htmltag(isset($companyConsts['MAIN_INFO_SOCIETE_TOWN']) ? $companyConsts['MAIN_INFO_SOCIETE_TOWN'] : '') . '"></td>';
	print '</tr>';

	// Pays
	print '<tr class="oddeven">';
	print '<td><label for="country_id">' . dol_escape_htmltag($langs->trans("CompanyCountry")) . '</label></td>';
	print '<td>' . $form->select_country($selectedCountryId, 'country_id') . '</td>';
	print '</tr>';

	// Devise
	print '<tr class="oddeven">';
	print '<td><label for="currency">' . dol_escape_htmltag($langs->trans("CompanyCurrency")) . '</label></td>';
	print '<td>' . $form->selectCurrency($storedCurrency, 'currency') . '</td>';
	print '</tr>';

	print '</table>';
	print '<div class="center"><br>';
	print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
	print ' &nbsp; <a class="button button-cancel" href="' . $selfUrl . '">' . $langs->trans("Cancel") . '</a>';
	print '</div>';
	print '</form>';
	print '</div>';
	print '<br>';
}

// -------------------------------------------------------------------------
// Formulaire de création d'une nouvelle entité
// -------------------------------------------------------------------------
print '<div class="div-table-responsive-no-min">';
print '<h2>' . $langs->trans("NewEntity") . '</h2>';
print '<form method="POST" action="' . $selfUrl . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="create">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("NewEntity") . '</td></tr>';

// Label
print '<tr class="oddeven">';
print '<td><label for="new_label">' . $langs->trans("EntityLabel") . ' <span class="fieldrequired">*</span></label></td>';
print '<td><input type="text" id="new_label" name="label" class="minwidth200" value="" required></td>';
print '</tr>';

// Code
print '<tr class="oddeven">';
print '<td><label for="new_code">' . $langs->trans("EntityCode") . '</label></td>';
print '<td><input type="text" id="new_code" name="code" class="minwidth100" value=""></td>';
print '</tr>';

// Couleur
print '<tr class="oddeven">';
print '<td><label for="new_color">' . $langs->trans("EntityColor") . ' <span class="opacitymedium">(#RRGGBB)</span></label></td>';
print '<td><input type="text" id="new_color" name="color" class="minwidth100" placeholder="#RRGGBB (ex. #029e9c)" value=""></td>';
print '</tr>';

// --- Informations société optionnelles (AC#5) ---
print '<tr class="liste_titre">';
print '<td colspan="2">' . dol_escape_htmltag($langs->trans("CompanyInfo")) . ' <span class="opacitymedium">(' . $langs->trans("Optional") . ')</span></td>';
print '</tr>';

// Nom société
print '<tr class="oddeven">';
print '<td><label for="new_company_nom">' . dol_escape_htmltag($langs->trans("CompanyName")) . '</label></td>';
print '<td><input type="text" id="new_company_nom" name="company_nom" class="minwidth200" value=""></td>';
print '</tr>';

// Adresse
print '<tr class="oddeven">';
print '<td><label for="new_company_address">' . dol_escape_htmltag($langs->trans("CompanyAddress")) . '</label></td>';
print '<td><input type="text" id="new_company_address" name="company_address" class="minwidth300" value=""></td>';
print '</tr>';

// Code postal
print '<tr class="oddeven">';
print '<td><label for="new_company_zip">' . dol_escape_htmltag($langs->trans("CompanyZip")) . '</label></td>';
print '<td><input type="text" id="new_company_zip" name="company_zip" class="minwidth100" value=""></td>';
print '</tr>';

// Ville
print '<tr class="oddeven">';
print '<td><label for="new_company_town">' . dol_escape_htmltag($langs->trans("CompanyTown")) . '</label></td>';
print '<td><input type="text" id="new_company_town" name="company_town" class="minwidth200" value=""></td>';
print '</tr>';

// Pays
print '<tr class="oddeven">';
print '<td><label for="country_id">' . dol_escape_htmltag($langs->trans("CompanyCountry")) . '</label></td>';
print '<td>' . $form->select_country(0, 'country_id') . '</td>';
print '</tr>';

// Devise
print '<tr class="oddeven">';
print '<td><label for="currency">' . dol_escape_htmltag($langs->trans("CompanyCurrency")) . '</label></td>';
print '<td>' . $form->selectCurrency('', 'currency') . '</td>';
print '</tr>';

print '</table>';
print '<div class="center"><br>';
print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
print '</div>';
print '</form>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
