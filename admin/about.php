<?php
/**
 * @file        admin/about.php
 * @brief       Page « À propos » du module MultiEntity
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
dol_include_once('/multientity/lib/version.lib.php');

$langs->loadLangs(array("admin", "multientity@multientity"));

if (!$user->admin) {
	accessforbidden();
}

llxHeader('', $langs->trans("MultiEntityAbout"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("MultiEntityAbout"), $linkback, 'building');

$head = multientityAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans("MultiEntity"), -1, 'building');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("Version") . '</td><td>' . dol_escape_htmltag(multientity_get_version()) . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("Editor") . '</td><td>P\'tite Tête</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("License") . '</td><td>GPL v3+</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans("MultiEntityIsolationNote") . '</td><td>' . $langs->trans("MultiEntityIsolationDesc") . '</td></tr>';
print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
