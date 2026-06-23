<?php
/**
 * @file        admin/user_entities.php
 * @brief       Page d'administration — affectation utilisateurs ↔ entités MultiEntity
 *
 * Permet à un super-administrateur de définir, pour chaque utilisateur,
 * la liste des entités auxquelles il a accès et son entité par défaut.
 * Source de vérité pour le contrôle d'accès E3 (switch d'entité).
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

$fk_user = GETPOSTINT('fk_user');
$action  = GETPOST('action', 'alphanohtml');

$service = new Multientity($db);
$form    = new Form($db);

// URL de la page échappée pour les sorties HTML (anti-XSS réfléchi via PATH_INFO sur PHP_SELF)
$selfUrl = dol_escape_htmltag($_SERVER['PHP_SELF']);

/*
 * Actions POST
 */

if ($action == 'save') {
	// Vérification CSRF
	if (GETPOST('token', 'alphanohtml') != newToken()) {
		accessforbidden('Invalid CSRF token');
		exit;
	}

	// Guard : un utilisateur cible doit être sélectionné (B2)
	if (!($fk_user > 0)) {
		setEventMessages($langs->trans("ErrorBadParameters"), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	// Récupération et normalisation des entités cochées (H2)
	$postedIds = (array) GETPOST('entity_ids', 'array');
	$ids = array_filter(
		array_map('intval', $postedIds),
		function ($v) { return $v > 0; }
	);
	$ids = array_values(array_unique($ids));

	$defaultId = GETPOSTINT('default_entity_id');

	// Si entités cochées : default obligatoire et ∈ ids (validation côté page)
	if (!empty($ids) && !in_array($defaultId, $ids, true)) {
		setEventMessages($langs->trans("DefaultMustBeAuthorized"), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF'] . '?fk_user=' . (int) $fk_user);
		exit;
	}

	$result = $service->setUserEntities($fk_user, $ids, $defaultId);

	if ($result < 0) {
		// Messages localisés pour les refus de validation (E3)
		if ($result == -2) {
			setEventMessages($langs->trans("EntityNotFound"), null, 'errors'); // user inexistant/inactif
		} elseif ($result == -3) {
			setEventMessages($langs->trans("EntityNotFound"), null, 'errors'); // entité inexistante/inactive
		} elseif ($result == -4) {
			setEventMessages($langs->trans("DefaultMustBeAuthorized"), null, 'errors');
		} else {
			setEventMessages($service->error, $service->errors, 'errors');
		}
	} elseif (empty($ids)) {
		setEventMessages($langs->trans("NoEntitySelected"), null, 'warnings');
	} else {
		setEventMessages($langs->trans("AssignmentsSaved"), null, 'mesgs');
	}

	header('Location: ' . $_SERVER['PHP_SELF'] . '?fk_user=' . (int) $fk_user);
	exit;
}

/*
 * Vue
 */

llxHeader('', $langs->trans("UserEntities") . ' — ' . $langs->trans("MultiEntity"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("MultiEntitySetup"), $linkback, 'building');

$head = multientityAdminPrepareHead();
print dol_get_fiche_head($head, 'userentities', $langs->trans("MultiEntity"), -1, 'building');

// -------------------------------------------------------------------------
// Sélecteur utilisateur
// -------------------------------------------------------------------------
print '<form method="GET" action="' . $selfUrl . '">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">' . $langs->trans("SelectUser") . '</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td><label for="fk_user">' . $langs->trans("User") . '</label></td>';
print '<td>';
// select_dolusers($selected, $htmlname, $show_empty, $exclude, $disabled, $filter, $selected_input_value, $enabledisabledusers, $realonly, $stringtoaddbeforeoption, $showuserlogin)
// 7e arg $enabledisabledusers=0 : exclut les désactivés (L2)
print $form->select_dolusers((int) $fk_user, 'fk_user', 1, array(), 0, '', 0);
print ' <input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("Select")) . '">';
print '</td>';
print '</tr>';
print '</table>';
print '</form>';

print '<br>';

// -------------------------------------------------------------------------
// Tableau d'affectation (affiché uniquement si un user est sélectionné)
// -------------------------------------------------------------------------
if ($fk_user > 0) {
	// Récupérer les affectations actuelles
	$userEntities = $service->getUserEntities($fk_user);
	if ($userEntities === -1) {
		setEventMessages($service->error, $service->errors, 'errors');
		$userEntities = array();
	}

	// Construire un set des entity_id autorisés et l'entity par défaut
	$authorizedSet = array();
	$currentDefault = 0;
	foreach ($userEntities as $ue) {
		$authorizedSet[] = (int) $ue->entity_id;
		if ($ue->is_default) {
			$currentDefault = (int) $ue->entity_id;
		}
	}

	// Récupérer les entités actives
	$activeEntities = $service->listEntities(true);
	if ($activeEntities === -1) {
		setEventMessages($service->error, $service->errors, 'errors');
		$activeEntities = array();
	}

	print '<form method="POST" action="' . $selfUrl . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="save">';
	print '<input type="hidden" name="fk_user" value="' . (int) $fk_user . '">';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("Id") . '</td>';
	print '<td>' . $langs->trans("EntityLabel") . '</td>';
	print '<td class="center">' . $langs->trans("AuthorizedEntities") . '</td>';
	print '<td class="center">' . $langs->trans("DefaultEntity") . '</td>';
	print '</tr>';

	if (is_array($activeEntities) && count($activeEntities) > 0) {
		foreach ($activeEntities as $ent) {
			$eid        = (int) $ent->entity_id;
			$isAuth     = in_array($eid, $authorizedSet, true);
			$isDefault  = ($eid === $currentDefault);

			print '<tr class="oddeven">';

			// entity_id
			print '<td>' . $eid . '</td>';

			// label
			print '<td>' . dol_escape_htmltag($ent->label) . '</td>';

			// case à cocher « autorisée »
			print '<td class="center">';
			print '<input type="checkbox" name="entity_ids[]" value="' . $eid . '"';
			if ($isAuth) {
				print ' checked';
			}
			print '>';
			print '</td>';

			// radio « par défaut »
			print '<td class="center">';
			print '<input type="radio" name="default_entity_id" value="' . $eid . '"';
			if ($isDefault) {
				print ' checked';
			}
			print '>';
			print '</td>';

			print '</tr>';
		}
	} else {
		print '<tr class="oddeven"><td colspan="4" class="center">' . $langs->trans("NoRecordFound") . '</td></tr>';
	}

	print '</table>';

	print '<div class="center"><br>';
	print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
	print ' &nbsp; <a class="button button-cancel" href="' . $selfUrl . '">' . $langs->trans("Cancel") . '</a>';
	print '</div>';

	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
