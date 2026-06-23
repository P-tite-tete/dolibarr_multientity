<?php
/**
 * @file        admin/setup.php
 * @brief       Page de configuration du module MultiEntity
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

// Traductions
$langs->loadLangs(array("admin", "multientity@multientity"));

// Sécurité : super-admin uniquement
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if ($action == 'setvalue') {
	if (GETPOST('token', 'alphanohtml') != newToken()) {
		accessforbidden('Invalid CSRF token');
	}

	$debug = GETPOSTINT('MULTIENTITY_DEBUG');
	dolibarr_set_const($db, 'MULTIENTITY_DEBUG', $debug, 'chaine', 0, '', $conf->entity);

	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("MultiEntitySetup"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("MultiEntitySetup"), $linkback, 'building');

$head = multientityAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("MultiEntity"), -1, 'building');

print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="setvalue">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans("Parameter") . '</td><td>' . $langs->trans("Value") . '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("MultiEntityDebugMode") . '</td><td>';
print $form->selectyesno('MULTIENTITY_DEBUG', getDolGlobalInt('MULTIENTITY_DEBUG'), 1);
print '</td></tr>';

print '</table>';

print '<div class="center"><br><input type="submit" class="button button-save" value="' . $langs->trans("Save") . '"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
