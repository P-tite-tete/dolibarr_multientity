<?php
/**
 * @file        test/smoke_multientity.php
 * @brief       Smoke test standalone pour la classe Multientity.
 *
 * Usage CLI :
 *   php test/smoke_multientity.php
 *
 * Ce script vérifie le scénario de base :
 *   create → listEntities → setActive (garde entité 1) → getUserEntities
 *
 * Script de test réservé au CLI : toute exécution via le web est refusée
 * (un script de smoke test ne doit jamais être déclenchable par requête HTTP).
 *
 * @package     MultiEntity
 * @subpackage  Test
 * @category    test
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <support@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     0.1.0
 * @since       0.1.0
 */

// ---------------------------------------------------------------------------
// Chargement de l'environnement Dolibarr
// ---------------------------------------------------------------------------
$res = false;
// CLI : le script est dans htdocs/custom/multientity/test/
if (!$res && file_exists(__DIR__ . '/../../../../main.inc.php')) {
	$res = @include __DIR__ . '/../../../../main.inc.php';
}
// Fallback : custom un niveau plus haut (install non-standard)
if (!$res && file_exists(__DIR__ . '/../../../main.inc.php')) {
	$res = @include __DIR__ . '/../../../main.inc.php';
}
// Fallback DOCUMENT_ROOT (appel via navigateur)
if (!$res && !empty($_SERVER['DOCUMENT_ROOT']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/main.inc.php')) {
	$res = @include $_SERVER['DOCUMENT_ROOT'] . '/main.inc.php';
}
if (!$res) {
	die("Include of main.inc.php fails — lancez depuis la racine htdocs ou via : php test/smoke_multientity.php\n");
}

// ---------------------------------------------------------------------------
// Sécurité : script CLI uniquement — jamais exécutable via le web
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
	accessforbidden('Smoke test reserved to CLI execution');
}

// ---------------------------------------------------------------------------
// Chargement de la classe à tester
// ---------------------------------------------------------------------------
dol_include_once('/multientity/class/multientity.class.php');

// ---------------------------------------------------------------------------
// Helpers d'affichage
// ---------------------------------------------------------------------------
$passed = 0;
$failed = 0;

/**
 * Affiche le résultat d'une assertion.
 *
 * @param string $label     Libellé du test.
 * @param bool   $condition Condition à vérifier.
 * @param string $detail    Détail optionnel affiché en cas d'échec.
 * @return void
 */
function smoke_assert($label, $condition, $detail = '')
{
	global $passed, $failed;
	if ($condition) {
		echo "[OK]   " . $label . "\n";
		$passed++;
	} else {
		echo "[FAIL] " . $label . ($detail ? " — " . $detail : "") . "\n";
		$failed++;
	}
}

// ---------------------------------------------------------------------------
// Instanciation du service
// ---------------------------------------------------------------------------
$service = new Multientity($db);

echo "\n=== Smoke test Multientity ===\n\n";

// ---------------------------------------------------------------------------
// 1. createEntity : création d'une entité de test
// ---------------------------------------------------------------------------
$labelTest = 'Entité smoke test ' . time();
$newId = $service->createEntity($labelTest, 'SMOKE', '#1A2B3C');
smoke_assert(
	'createEntity retourne un entity_id > 0',
	is_int($newId) && $newId > 0,
	'retour=' . var_export($newId, true)
);

// Test couleur invalide : doit créer sans erreur mais color = NULL en base
$idBadColor = $service->createEntity('Entité couleur invalide smoke', 'BADCLR', 'rouge');
smoke_assert(
	'createEntity avec couleur invalide retourne entity_id > 0 (color ignorée)',
	is_int($idBadColor) && $idBadColor > 0,
	'retour=' . var_export($idBadColor, true)
);

// Test refus entity_id doublon
if ($newId > 0) {
	$dupId = $service->createEntity('Doublon', null, null, $newId);
	smoke_assert(
		'createEntity refuse entity_id déjà présent (retour <0)',
		is_int($dupId) && $dupId < 0,
		'retour=' . var_export($dupId, true)
	);
}

// ---------------------------------------------------------------------------
// 2. listEntities : la nouvelle entité doit apparaître
// ---------------------------------------------------------------------------
$list = $service->listEntities();
smoke_assert(
	'listEntities retourne un tableau',
	is_array($list),
	'retour=' . gettype($list)
);

$found = false;
if (is_array($list) && $newId > 0) {
	foreach ($list as $ent) {
		if ((int) $ent->entity_id === $newId) {
			$found = true;
			break;
		}
	}
}
smoke_assert(
	'listEntities contient l\'entité créée (entity_id=' . $newId . ')',
	$found
);

// listEntities($activeOnly=true) ne doit PAS contenir une entité désactivée
// (vérification après setActive ci-dessous)

// ---------------------------------------------------------------------------
// 3. getEntity : récupérer l'entité créée
// ---------------------------------------------------------------------------
if ($newId > 0) {
	$ent = $service->getEntity($newId);
	smoke_assert(
		'getEntity retourne un objet pour entity_id=' . $newId,
		is_object($ent),
		'retour=' . gettype($ent)
	);
	smoke_assert(
		'getEntity : label correspond',
		is_object($ent) && $ent->label === $labelTest,
		is_object($ent) ? 'label=' . $ent->label : ''
	);
}

$entNull = $service->getEntity(999999);
smoke_assert(
	'getEntity retourne null pour entity_id inexistant',
	$entNull === null
);

// ---------------------------------------------------------------------------
// 4. setActive : garde entité 1 — désactivation refusée
// ---------------------------------------------------------------------------
$guardResult = $service->setActive(1, false);
smoke_assert(
	'setActive(1, false) refusé — retour <0 (garde entité 1)',
	is_int($guardResult) && $guardResult < 0,
	'retour=' . var_export($guardResult, true)
);

// Activation de l'entité 1 reste autorisée
$activateResult = $service->setActive(1, true);
smoke_assert(
	'setActive(1, true) autorisé — retour 1',
	$activateResult === 1,
	'retour=' . var_export($activateResult, true)
);

// Désactivation de l'entité de test
if ($newId > 0) {
	$deactResult = $service->setActive($newId, false);
	smoke_assert(
		'setActive(' . $newId . ', false) retourne 1',
		$deactResult === 1,
		'retour=' . var_export($deactResult, true)
	);

	// listEntities(activeOnly=true) ne doit plus contenir l'entité désactivée
	$listActive = $service->listEntities(true);
	$foundActive = false;
	if (is_array($listActive)) {
		foreach ($listActive as $e) {
			if ((int) $e->entity_id === $newId) {
				$foundActive = true;
				break;
			}
		}
	}
	smoke_assert(
		'listEntities(activeOnly=true) exclut l\'entité désactivée',
		!$foundActive
	);
}

// ---------------------------------------------------------------------------
// 5. getUserEntities : user inexistant → liste vide
// ---------------------------------------------------------------------------
$userEnts = $service->getUserEntities(999999);
smoke_assert(
	'getUserEntities pour user inexistant retourne tableau vide',
	is_array($userEnts) && count($userEnts) === 0,
	'retour=' . var_export($userEnts, true)
);

// ---------------------------------------------------------------------------
// 6. getDefaultEntity : fallback sur 1 pour user sans affectation
// ---------------------------------------------------------------------------
$defaultEnt = $service->getDefaultEntity(999999);
smoke_assert(
	'getDefaultEntity retourne 1 (fallback) pour user sans affectation',
	$defaultEnt === 1,
	'retour=' . var_export($defaultEnt, true)
);

// ---------------------------------------------------------------------------
// 7. getEntityLabel : délègue à multientity_get_entity_label
// ---------------------------------------------------------------------------
$label1 = $service->getEntityLabel(1);
smoke_assert(
	'getEntityLabel(1) retourne une chaîne non vide',
	is_string($label1) && strlen($label1) > 0,
	'retour=' . var_export($label1, true)
);

// ---------------------------------------------------------------------------
// Résumé
// ---------------------------------------------------------------------------
echo "\n--- Résultats ---\n";
echo "Passés : " . $passed . "\n";
echo "Échoués : " . $failed . "\n";

if ($failed > 0) {
	echo "\nDes assertions ont échoué.\n";
	exit(1);
} else {
	echo "\nTous les tests sont passés.\n";
	exit(0);
}
